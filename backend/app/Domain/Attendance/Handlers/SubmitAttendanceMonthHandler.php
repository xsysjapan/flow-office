<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Commands\SubmitAttendanceMonth;
use App\Domain\Attendance\Events\AttendanceMonthSubmitted;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Jobs\SendTeamsNotificationJob;
use App\Models\AttendanceDailyCalculation;
use App\Models\AttendanceDay;
use App\Models\AttendanceMonth;
use App\Models\AttendanceMonthStatus;
use Illuminate\Support\Carbon;

/**
 * UC-A008: 月次勤怠を提出する。
 *
 * @implements CommandHandler<SubmitAttendanceMonth>
 */
class SubmitAttendanceMonthHandler implements CommandHandler
{
    public function __construct(private readonly EventStore $eventStore) {}

    public function handle(Command $command): AttendanceMonth
    {
        assert($command instanceof SubmitAttendanceMonth);

        $month = AttendanceMonth::query()->firstOrCreate(
            ['user_id' => $command->userId, 'year_month' => $command->yearMonth],
            ['status' => AttendanceMonthStatus::NOT_SUBMITTED],
        );

        if (! in_array($month->status, [AttendanceMonthStatus::NOT_SUBMITTED, AttendanceMonthStatus::RETURNED], true)) {
            throw new DomainRuleException('この月次勤怠は現在のステータスからは提出できません。');
        }

        $month->status = AttendanceMonthStatus::SUBMITTED;
        $month->approver_user_id = $command->approverUserId;
        $month->submitted_at = Carbon::now();
        $month->snapshot_json = $this->buildSnapshot($command->userId, $command->yearMonth);
        $month->save();

        $this->eventStore->append(
            aggregateType: 'attendance_month',
            aggregateId: (string) $month->id,
            event: new AttendanceMonthSubmitted(
                attendanceMonthId: $month->id,
                userId: $command->userId,
                yearMonth: $command->yearMonth,
                approverUserId: $command->approverUserId,
            ),
        );

        SendTeamsNotificationJob::enqueue(
            title: '月次勤怠の承認依頼',
            summary: "{$command->yearMonth} の月次勤怠が提出されました。",
            detailUrl: null,
        );

        return $month;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSnapshot(int $userId, string $yearMonth): array
    {
        $dayIds = AttendanceDay::query()
            ->where('user_id', $userId)
            ->where('work_date', 'like', "{$yearMonth}%")
            ->pluck('id');

        $calculations = AttendanceDailyCalculation::query()->whereIn('attendance_day_id', $dayIds)->get();

        return [
            'day_count' => $dayIds->count(),
            'actual_work_minutes' => $calculations->sum('actual_work_minutes'),
            'payroll_work_minutes' => $calculations->sum('payroll_work_minutes'),
            'prescribed_work_minutes' => $calculations->sum('prescribed_work_minutes'),
            'non_statutory_overtime_minutes' => $calculations->sum('non_statutory_overtime_minutes'),
            'statutory_overtime_minutes' => $calculations->sum('statutory_overtime_minutes'),
            'late_night_minutes' => $calculations->sum('late_night_minutes'),
            'legal_holiday_work_minutes' => $calculations->sum('legal_holiday_work_minutes'),
            'company_holiday_work_minutes' => $calculations->sum('company_holiday_work_minutes'),
        ];
    }
}
