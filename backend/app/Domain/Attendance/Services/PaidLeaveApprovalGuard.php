<?php

namespace App\Domain\Attendance\Services;

use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\PaidLeaveRequest;
use App\Models\PaidLeaveRequestStatus;
use Illuminate\Support\Carbon;

/** 月次勤怠の提出前に、対象月の有給申請がすべて承認済みであることを保証する。 */
class PaidLeaveApprovalGuard
{
    public function ensureApproved(string $userId, string $yearMonth): void
    {
        $periodStart = Carbon::parse($yearMonth.'-01');
        $periodEnd = $periodStart->copy()->endOfMonth();

        $hasUnapprovedRequest = PaidLeaveRequest::query()
            ->where('user_id', $userId)
            ->whereBetween('target_date', [$periodStart->toDateString(), $periodEnd->toDateString()])
            ->whereIn('status', [PaidLeaveRequestStatus::SUBMITTED, PaidLeaveRequestStatus::RETURNED])
            ->exists();

        if ($hasUnapprovedRequest) {
            throw new DomainRuleException('対象月に未承認の有給申請があります。有給申請の承認を完了してから月次勤怠を提出してください。');
        }
    }
}
