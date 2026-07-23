<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\AttendanceMonthAggregate;
use App\Domain\Attendance\Commands\SubmitAttendanceMonth;
use App\Domain\Attendance\Services\MonthlyOvertimeCalculator;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Jobs\SendNotificationJob;
use App\Models\AttendanceDay;
use App\Models\AttendanceMonth;
use App\Models\AttendanceMonthStatus;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * UC-A008: 月次勤怠を提出する。
 *
 * @implements CommandHandler<SubmitAttendanceMonth>
 */
class SubmitAttendanceMonthHandler implements CommandHandler
{
    public function __construct(
        private readonly MonthlyOvertimeCalculator $monthlyOvertimeCalculator,
    ) {}

    public function handle(Command $command): AttendanceMonth
    {
        assert($command instanceof SubmitAttendanceMonth);

        $month = AttendanceMonth::query()
            ->where('user_id', $command->userId)
            ->where('year_month', $command->yearMonth)
            ->first();

        if ($month !== null && ! in_array($month->status, [AttendanceMonthStatus::NOT_SUBMITTED, AttendanceMonthStatus::RETURNED], true)) {
            throw new DomainRuleException('この月次勤怠は現在のステータスからは提出できません。');
        }

        $monthId = $month->id ?? (string) Str::uuid();
        $snapshot = $this->buildSnapshot($command->userId, $command->yearMonth);

        AttendanceMonthAggregate::retrieve($monthId)
            ->submit($command->userId, $command->yearMonth, $command->approverUserId, $snapshot)
            ->persist();

        $month = AttendanceMonth::query()->findOrFail($monthId);

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
