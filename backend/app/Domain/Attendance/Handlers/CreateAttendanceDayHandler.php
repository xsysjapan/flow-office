<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Commands\CreateAttendanceDay;
use App\Domain\Attendance\Events\AttendanceDayCalculated;
use App\Domain\Attendance\Events\AttendanceDayCreated;
use App\Domain\Attendance\Services\AttendanceCalculator;
use App\Domain\Attendance\Services\AttendanceEditGuard;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendanceDay;
use App\Models\AttendanceDaySource;
use App\Models\AttendanceDayStatus;
use App\Models\EmployeeShiftAssignment;
use App\Models\SystemSetting;
use App\Support\LocalDateTime;
use Illuminate\Support\Carbon;

/**
 * 出勤日(attendance_days)を任意の勤務日に新規作成する。打刻(attendance_punches)とは
 * 勤務日が同じというだけの緩い関係しかなく、打刻の有無にかかわらず作成できる。その月が
 * 編集不可(承認済み・締め済み)になるまでは、いつでも作成できる(AttendanceEditGuard参照)。
 *
 * @implements CommandHandler<CreateAttendanceDay>
 */
class CreateAttendanceDayHandler implements CommandHandler
{
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly AttendanceCalculator $calculator,
        private readonly AttendanceEditGuard $guard,
    ) {}

    public function handle(Command $command): AttendanceDay
    {
        assert($command instanceof CreateAttendanceDay);

        $exists = AttendanceDay::query()
            ->where('user_id', $command->userId)
            ->whereDate('work_date', $command->workDate)
            ->exists();

        if ($exists) {
            throw new DomainRuleException('指定した勤務日の出勤日は既に存在します。日次編集を使用してください。');
        }

        $this->guard->assertMutable(null, $command->userId, $command->workDate);

        $offsetMinutes = $this->resolveOffsetMinutes($command);

        $shiftAssignment = EmployeeShiftAssignment::query()
            ->where('user_id', $command->userId)
            ->whereDate('work_date', $command->workDate)
            ->first();

        $day = new AttendanceDay([
            'user_id' => $command->userId,
            'work_date' => $command->workDate,
            'shift_assignment_id' => $shiftAssignment?->id,
            'status' => $command->actualEndAt !== null ? AttendanceDayStatus::CLOCKED_OUT : AttendanceDayStatus::NOT_STARTED,
            'source' => AttendanceDaySource::MANUAL,
            'utc_offset_minutes' => $offsetMinutes,
            'actual_start_at' => $command->actualStartAt !== null ? LocalDateTime::splitOffset($command->actualStartAt)[0] : null,
            'actual_end_at' => $command->actualEndAt !== null ? LocalDateTime::splitOffset($command->actualEndAt)[0] : null,
            'work_type' => $command->workType,
            'note' => $command->note,
        ]);
        $day->save();

        foreach ($command->breaks as $break) {
            $day->breaks()->create([
                'break_start_at' => LocalDateTime::splitOffset($break['start'])[0],
                'break_end_at' => $break['end'] !== null ? LocalDateTime::splitOffset($break['end'])[0] : null,
            ]);
        }

        $this->eventStore->append(
            aggregateType: 'attendance_day',
            aggregateId: (string) $day->id,
            event: new AttendanceDayCreated(
                attendanceDayId: $day->id,
                userId: $command->userId,
                workDate: $command->workDate,
                reason: $command->reason,
                createdByUserId: $command->createdByUserId,
            ),
        );

        $calculation = $this->calculator->calculate($day->refresh()->load('breaks', 'shiftAssignment.workStyle'));

        $this->eventStore->append(
            aggregateType: 'attendance_day',
            aggregateId: (string) $day->id,
            event: new AttendanceDayCalculated(
                attendanceDayId: $day->id,
                calculation: $calculation,
            ),
        );

        return $day;
    }

    /**
     * 今回の作成で送られた日時(actual_start_at / actual_end_at / breaks[].start / breaks[].end)
     * のオフセットが全て一致することを検証し、その値を返す。1件も送られなかった場合は
     * システムのデフォルトタイムゾーン(`system_settings.default_timezone`)のオフセットを使う
     * (裁量労働制など、打刻を伴わない出勤日の作成を想定。docs/03-architecture.md 3.4)。
     */
    private function resolveOffsetMinutes(CreateAttendanceDay $command): int
    {
        $inputs = array_filter([
            $command->actualStartAt,
            $command->actualEndAt,
            ...array_column($command->breaks, 'start'),
            ...array_column($command->breaks, 'end'),
        ], fn (?string $value) => $value !== null);

        $resolved = null;
        foreach ($inputs as $input) {
            [, $offsetMinutes] = LocalDateTime::splitOffset($input);
            if ($resolved !== null && $resolved !== $offsetMinutes) {
                throw new DomainRuleException('同一勤務日の時刻はすべて同じタイムゾーンオフセットで入力してください。');
            }
            $resolved = $offsetMinutes;
        }

        if ($resolved !== null) {
            return $resolved;
        }

        $defaultTimezone = SystemSetting::current()->default_timezone;

        return intdiv(Carbon::parse($command->workDate, $defaultTimezone)->getOffset(), 60);
    }
}
