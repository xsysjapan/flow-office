<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Commands\SubmitAttendanceMonth;
use App\Domain\Attendance\Events\AttendanceMonthSubmitted;
use App\Domain\Attendance\Services\MonthlyOvertimeCalculator;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\EventStore;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Jobs\SendNotificationJob;
use App\Models\AttendanceDay;
use App\Models\AttendanceMonth;
use App\Models\AttendanceMonthStatus;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * UC-A008: 月次勤怠を提出する。
 *
 * @implements CommandHandler<SubmitAttendanceMonth>
 */
class SubmitAttendanceMonthHandler implements CommandHandler
{
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly MonthlyOvertimeCalculator $monthlyOvertimeCalculator,
    ) {}

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

        $approver = User::find($command->approverUserId);
        if ($approver !== null) {
            SendNotificationJob::enqueue(
                recipient: $approver,
                title: '月次勤怠の承認依頼',
                summary: "{$command->yearMonth} の月次勤怠が提出されました。",
                detailUrl: null,
            );
        }

        return $month;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSnapshot(string $userId, string $yearMonth): array
    {
        $dayCount = AttendanceDay::query()
            ->where('user_id', $userId)
            ->where('work_date', 'like', "{$yearMonth}%")
            ->count();

        return array_merge(
            ['day_count' => $dayCount],
            $this->monthlyOvertimeCalculator->calculateCategoryTotals($userId, $yearMonth),
        );
    }
}
