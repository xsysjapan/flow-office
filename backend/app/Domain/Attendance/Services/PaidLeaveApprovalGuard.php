<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Support\AttendanceDayLeaves;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendanceDayLeave;
use Illuminate\Support\Carbon;

/**
 * 月次勤怠の提出前に、対象月の有給申請に申請中(承認待ち)のものがないことを保証する(論点14)。
 *
 * 判定は勤怠の休暇ビュー(attendance_day_leaves)の有給・申請中(submitted)の行で行う。
 * 差し戻された有給は申請前の状態のため対象外(論点8)。承認済み・取消済みも対象外。
 */
class PaidLeaveApprovalGuard
{
    public function __construct(private readonly AttendanceDayLeaves $leaves) {}

    public function ensureApproved(string $userId, string $yearMonth): void
    {
        $periodStart = Carbon::parse($yearMonth.'-01');
        $periodEnd = $periodStart->copy()->endOfMonth();

        $hasUnapprovedRequest = $this->leaves->hasSubmittedOfKindIn(
            $userId,
            AttendanceDayLeave::KIND_PAID,
            $periodStart->toDateString(),
            $periodEnd->toDateString(),
        );

        if ($hasUnapprovedRequest) {
            throw new DomainRuleException('対象月に未承認の有給申請があります。有給申請の承認を完了してから月次勤怠を提出してください。');
        }
    }
}
