<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Aggregates\AttendanceMonthAggregate;
use App\Domain\Attendance\Commands\SubmitAttendanceMonth;
use App\Domain\Attendance\Services\MonthlyOvertimeCalculator;
use App\Domain\Attendance\Services\PaidLeaveApprovalGuard;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendanceDay;
use App\Models\AttendanceDayStatus;
use App\Models\AttendanceMonth;
use App\Models\AttendanceMonthStatus;
use App\Models\SystemSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * UC-A008: 月次勤怠を提出する。出勤中・休憩中のまま確定していない日(打刻漏れ・退勤忘れ)が
 * 対象月内にある間は提出できない。
 *
 * @implements CommandHandler<SubmitAttendanceMonth>
 */
class SubmitAttendanceMonthHandler implements CommandHandler
{
    public function __construct(
        private readonly MonthlyOvertimeCalculator $monthlyOvertimeCalculator,
        private readonly PaidLeaveApprovalGuard $paidLeaveApprovalGuard,
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

        if ($this->hasUnfinishedDay($command->userId, $command->yearMonth)) {
            throw new DomainRuleException('勤務中・休憩中の日があるため提出できません。退勤してから提出してください。');
        }

        $this->paidLeaveApprovalGuard->ensureApproved($command->userId, $command->yearMonth);

        // 月次勤怠申請(workflow_requestの下書きが先に作られる経路)では、subject_idとして
        // 確定済みの集約IDがコマンドに載ってくるのでそれを優先する。
        $monthId = $command->attendanceMonthId ?? $month->id ?? (string) Str::uuid();
        $snapshot = $this->buildSnapshot($command->userId, $command->yearMonth);
        $periodStart = "{$command->yearMonth}-01";
        $periodEnd = Carbon::parse($periodStart)->endOfMonth()->toDateString();

        $aggregate = AttendanceMonthAggregate::retrieve($monthId)
            ->submit($command->userId, $command->yearMonth, $command->approverUserId, $snapshot, $periodStart, $periodEnd, $command->workflowRequestId);

        // attendance_requires_approval=falseの場合、承認ワークフロー無しで提出と同時に
        // 承認不要のまま即時確定する(ExpenseClaimのapproval_skip_thresholdによる
        // 自動承認と同じ仕組み)。
        if (! SystemSetting::current()->attendance_requires_approval) {
            $aggregate->approve(approvedByUserId: null);
        }

        $aggregate->persist();

        // 承認依頼の通知はSubmitWorkflowRequestHandler(WorkflowRequestNotificationContent)に
        // 一本化しているため、ここでは送らない。
        return AttendanceMonth::query()->findOrFail($monthId);
    }

    /** 出勤中・休憩中のまま確定していない日が対象月内にあれば提出させない。 */
    private function hasUnfinishedDay(string $userId, string $yearMonth): bool
    {
        return AttendanceDay::query()
            ->where('user_id', $userId)
            ->where('work_date', 'like', "{$yearMonth}%")
            ->whereIn('status', [AttendanceDayStatus::WORKING, AttendanceDayStatus::ON_BREAK])
            ->exists();
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
