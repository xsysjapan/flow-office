<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\AttendanceDayAggregate;
use App\Domain\Attendance\Aggregates\LegalHolidayDesignationAggregate;
use App\Domain\Attendance\Commands\DesignateLegalHoliday;
use App\Domain\Attendance\Services\AttendanceCalculator;
use App\Domain\Attendance\Services\AttendanceEditGuard;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendanceDay;
use App\Models\EmployeeShiftAssignment;
use App\Models\LegalHolidayDesignation;
use App\Models\WorkStyle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * 法定休日「決めない方式」(work_styles.legal_holiday_rule=undetermined)における、
 * 特定の週の法定休日を指定する(docs/08-usecases-calendar-shift.md UC-C007参照)。
 *
 * 指定によって法定休日の判定が変わるため、週内に既にある出勤日(attendance_days)の
 * 日次計算を再実行する。ただし締め・承認済みの日は対象外とする(AttendanceEditGuard)。
 *
 * 指定(legal_holiday_designation集約)+週内の日次再計算(attendance_day集約複数件)を
 * `AggregateRoot::persistInTransaction()`で1トランザクションにまとめて記録する
 * (docs/29-event-sourcing-framework-migration.md「移行済み: PaidLeave / SpecialLeave」と同じ
 * 複数集約トランザクションのパターン)。ただし日次再計算はLegalHolidayResolverが
 * `legal_holiday_designations`を直接読んで法定休日を判定するため、再計算より前に
 * この指定内容をProjectionへ直接反映しておく必要がある(PaidLeaveの`$day->work_type`直接
 * 反映と同じ理由。イベント自体はpersistInTransactionでまとめて記録するため、
 * LegalHolidayDesignationProjectorが後から同じ内容をupdateOrCreateしても冪等)。
 *
 * @implements CommandHandler<DesignateLegalHoliday>
 */
class DesignateLegalHolidayHandler implements CommandHandler
{
    public function __construct(
        private readonly AttendanceCalculator $calculator,
        private readonly AttendanceEditGuard $guard,
    ) {}

    public function handle(Command $command): LegalHolidayDesignation
    {
        assert($command instanceof DesignateLegalHoliday);

        $weekStart = Carbon::parse($command->weekStartDate)->startOfDay();
        $weekEnd = $weekStart->copy()->addDays(6);
        $designatedDate = Carbon::parse($command->designatedDate)->startOfDay();

        if ($designatedDate->lt($weekStart) || $designatedDate->gt($weekEnd)) {
            throw new DomainRuleException('指定日はその週(week_start_dateから7日間)の範囲内である必要があります。');
        }

        $workStyle = $this->resolveWorkStyle($command->userId, $weekStart, $weekEnd);

        if ($workStyle === null || $workStyle->legal_holiday_rule !== WorkStyle::LEGAL_HOLIDAY_RULE_UNDETERMINED) {
            throw new DomainRuleException('この勤務形態は法定休日「決めない方式」ではありません。');
        }

        $this->guard->assertMutable(null, $command->userId, $designatedDate->toDateString());

        $existing = LegalHolidayDesignation::query()
            ->where('user_id', $command->userId)
            ->whereDate('week_start_date', $weekStart->toDateString())
            ->first();

        $id = $existing?->id ?? (string) Str::uuid();
        $previousDesignatedDate = $existing?->designated_date?->toDateString();

        $designation = $existing ?? new LegalHolidayDesignation([
            'id' => $id,
            'user_id' => $command->userId,
            'week_start_date' => $weekStart->toDateString(),
        ]);

        // LegalHolidayResolverが直後の日次再計算でこの指定を読めるよう、先にProjectionへ
        // 反映しておく(イベント自体はpersistInTransactionでまとめて記録する)。
        $designation->fill([
            'designated_date' => $designatedDate->toDateString(),
            'reason' => $command->reason,
            'designated_by' => $command->designatedByUserId,
        ])->save();

        $aggregates = [
            LegalHolidayDesignationAggregate::retrieve($id)->designate(
                userId: $command->userId,
                weekStartDate: $weekStart->toDateString(),
                previousDesignatedDate: $previousDesignatedDate,
                designatedDate: $designatedDate->toDateString(),
                reason: $command->reason,
                designatedByUserId: $command->designatedByUserId,
            ),
        ];

        foreach ($this->planWeekRecalculation($command->userId, $weekStart, $weekEnd) as ['dayId' => $dayId, 'calculation' => $calculation]) {
            $aggregates[] = AttendanceDayAggregate::retrieve($dayId)->calculate($calculation);
        }

        AggregateRoot::persistInTransaction(...$aggregates);

        return $designation->refresh();
    }

    private function resolveWorkStyle(string $userId, Carbon $weekStart, Carbon $weekEnd): ?WorkStyle
    {
        return EmployeeShiftAssignment::query()
            ->where('user_id', $userId)
            ->whereDate('work_date', '>=', $weekStart->toDateString())
            ->whereDate('work_date', '<=', $weekEnd->toDateString())
            ->orderBy('work_date')
            ->with('workStyle.calendar')
            ->first()
            ?->workStyle;
    }

    /**
     * 週内の対象日の日次計算を確定させる(この時点ではまだイベントを記録しない。
     * ApprovePaidLeaveRequestHandlerのplanConsumption()と同じ考え方)。
     *
     * @return list<array{dayId: string, calculation: array<string, int|bool|float|null>}>
     */
    private function planWeekRecalculation(string $userId, Carbon $weekStart, Carbon $weekEnd): array
    {
        $days = AttendanceDay::query()
            ->where('user_id', $userId)
            ->whereDate('work_date', '>=', $weekStart->toDateString())
            ->whereDate('work_date', '<=', $weekEnd->toDateString())
            ->get();

        $plan = [];

        foreach ($days as $day) {
            if (! $this->guard->isMutable($day, $userId, $day->work_date->toDateString())) {
                continue;
            }

            $calculation = $this->calculator->calculate($day->load('breaks', 'leaveSegments', 'paidLeaveUsages', 'specialLeaveUsages', 'shiftAssignment.workStyle.calendar'));

            $plan[] = ['dayId' => $day->id, 'calculation' => $calculation];
        }

        return $plan;
    }
}
