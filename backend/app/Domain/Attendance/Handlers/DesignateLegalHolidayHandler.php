<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Commands\DesignateLegalHoliday;
use App\Domain\Attendance\Events\AttendanceDayCalculated;
use App\Domain\Attendance\Events\LegalHolidayDesignated;
use App\Domain\Attendance\Services\AttendanceCalculator;
use App\Domain\Attendance\Services\AttendanceEditGuard;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendanceDay;
use App\Models\EmployeeShiftAssignment;
use App\Models\LegalHolidayDesignation;
use App\Models\WorkStyle;
use Illuminate\Support\Carbon;

/**
 * 法定休日「決めない方式」(work_styles.legal_holiday_rule=undetermined)における、
 * 特定の週の法定休日を指定する(docs/08-usecases-calendar-shift.md UC-C007参照)。
 *
 * 指定によって法定休日の判定が変わるため、週内に既にある出勤日(attendance_days)の
 * 日次計算を再実行する。ただし締め・承認済みの日は対象外とする(AttendanceEditGuard)。
 *
 * @implements CommandHandler<DesignateLegalHoliday>
 */
class DesignateLegalHolidayHandler implements CommandHandler
{
    public function __construct(
        private readonly EventStore $eventStore,
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

        $designation = LegalHolidayDesignation::query()
            ->where('user_id', $command->userId)
            ->whereDate('week_start_date', $weekStart->toDateString())
            ->first() ?? new LegalHolidayDesignation([
                'user_id' => $command->userId,
                'week_start_date' => $weekStart->toDateString(),
            ]);

        $previousDesignatedDate = $designation->exists ? $designation->designated_date->toDateString() : null;

        $designation->fill([
            'designated_date' => $designatedDate->toDateString(),
            'reason' => $command->reason,
            'designated_by' => $command->designatedByUserId,
        ])->save();

        $this->eventStore->append(
            aggregateType: 'legal_holiday_designation',
            aggregateId: (string) $designation->id,
            event: new LegalHolidayDesignated(
                userId: $command->userId,
                weekStartDate: $weekStart->toDateString(),
                previousDesignatedDate: $previousDesignatedDate,
                designatedDate: $designatedDate->toDateString(),
                reason: $command->reason,
                designatedByUserId: $command->designatedByUserId,
            ),
        );

        $this->recalculateWeek($command->userId, $weekStart, $weekEnd);

        return $designation;
    }

    private function resolveWorkStyle(int $userId, Carbon $weekStart, Carbon $weekEnd): ?WorkStyle
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

    private function recalculateWeek(int $userId, Carbon $weekStart, Carbon $weekEnd): void
    {
        $days = AttendanceDay::query()
            ->where('user_id', $userId)
            ->whereDate('work_date', '>=', $weekStart->toDateString())
            ->whereDate('work_date', '<=', $weekEnd->toDateString())
            ->get();

        foreach ($days as $day) {
            if (! $this->guard->isMutable($day, $userId, $day->work_date->toDateString())) {
                continue;
            }

            $calculation = $this->calculator->calculate($day->load('breaks', 'shiftAssignment.workStyle.calendar'));

            $this->eventStore->append(
                aggregateType: 'attendance_day',
                aggregateId: (string) $day->id,
                event: new AttendanceDayCalculated(
                    attendanceDayId: $day->id,
                    calculation: $calculation,
                ),
            );
        }
    }
}
