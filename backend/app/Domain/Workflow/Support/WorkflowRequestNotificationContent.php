<?php

namespace App\Domain\Workflow\Support;

use App\Models\AttendanceMonth;
use App\Models\ExpenseClaim;
use App\Models\WorkflowRequest;
use App\Support\FrontendUrl;

/**
 * 申請の提出・承認・差戻し通知の文言(title/summary/detailUrl)を`subject_type`に応じて
 * 組み立てる。
 *
 * 承認関連の通知は Submit/Approve/ReturnWorkflowRequestHandler を唯一の送信元とし、
 * 対象ドメイン(月次勤怠・経費精算)側のHandlerからは送らない
 * (ルートCLAUDE.md「操作経路と業務ロジックを分離する」)。`subject_type`がnullの
 * 従来の汎用申請では、これまでと同じ文言を返す。
 */
final class WorkflowRequestNotificationContent
{
    public const ATTENDANCE_MONTH = 'attendance_month';

    public const EXPENSE_CLAIM = 'expense_claim';

    public const PAID_LEAVE_REQUEST = 'paid_leave_request';

    public const SPECIAL_LEAVE_REQUEST = 'special_leave_request';

    public const SHIFT_SWAP_REQUEST = 'shift_swap_request';

    private function __construct(
        public readonly string $title,
        public readonly string $summary,
        public readonly string $detailUrl,
    ) {}

    public static function forSubmitted(WorkflowRequest $workflowRequest): self
    {
        return match ($workflowRequest->subject_type) {
            self::ATTENDANCE_MONTH => new self(
                title: '月次勤怠の承認依頼',
                summary: self::yearMonth($workflowRequest).' の月次勤怠が提出されました。',
                detailUrl: FrontendUrl::path("/approvals?requestId={$workflowRequest->id}"),
            ),
            self::EXPENSE_CLAIM => new self(
                title: '経費精算の承認依頼',
                summary: '「'.self::claimTitle($workflowRequest).'」の経費精算が提出されました。',
                detailUrl: FrontendUrl::path("/approvals?requestId={$workflowRequest->id}"),
            ),
            self::PAID_LEAVE_REQUEST => new self(
                title: '有給休暇申請の承認依頼',
                summary: '有給休暇申請が提出されました。',
                detailUrl: FrontendUrl::path("/approvals?requestId={$workflowRequest->id}"),
            ),
            self::SPECIAL_LEAVE_REQUEST => new self(
                title: '特別休暇申請の承認依頼',
                summary: '特別休暇申請が提出されました。',
                detailUrl: FrontendUrl::path("/approvals?requestId={$workflowRequest->id}"),
            ),
            self::SHIFT_SWAP_REQUEST => new self(
                title: '振替休日申請の承認依頼',
                summary: '振替休日申請が提出されました。',
                detailUrl: FrontendUrl::path("/approvals?requestId={$workflowRequest->id}"),
            ),
            default => new self(
                title: '承認依頼',
                summary: "「{$workflowRequest->title}」の承認依頼が届いています。",
                detailUrl: FrontendUrl::path("/requests/{$workflowRequest->id}"),
            ),
        };
    }

    public static function forApproved(WorkflowRequest $workflowRequest): self
    {
        return match ($workflowRequest->subject_type) {
            self::ATTENDANCE_MONTH => new self(
                title: '月次勤怠が承認されました',
                summary: self::yearMonth($workflowRequest).' の月次勤怠が承認されました。バックオフィス確認対象になります。',
                detailUrl: FrontendUrl::path('/attendance/months/'.self::yearMonth($workflowRequest)),
            ),
            self::EXPENSE_CLAIM => new self(
                title: '経費精算が承認されました',
                summary: '「'.self::claimTitle($workflowRequest).'」の経費精算が承認されました。',
                detailUrl: FrontendUrl::path('/expenses/'.$workflowRequest->subject_id),
            ),
            self::PAID_LEAVE_REQUEST => new self(
                title: '有給休暇申請が承認されました',
                summary: '有給休暇申請が承認されました。',
                detailUrl: FrontendUrl::path('/paid-leave/requests'),
            ),
            self::SPECIAL_LEAVE_REQUEST => new self(
                title: '特別休暇申請が承認されました',
                summary: '特別休暇申請が承認されました。',
                detailUrl: FrontendUrl::path('/special-leave/requests'),
            ),
            self::SHIFT_SWAP_REQUEST => new self(
                title: '振替休日申請が承認されました',
                summary: '振替休日申請が承認されました。',
                detailUrl: FrontendUrl::path('/shift-swap/requests'),
            ),
            default => new self(
                title: '承認完了',
                summary: "「{$workflowRequest->title}」が承認されました。",
                detailUrl: FrontendUrl::path("/requests/{$workflowRequest->id}"),
            ),
        };
    }

    public static function forReturned(WorkflowRequest $workflowRequest, string $comment): self
    {
        return match ($workflowRequest->subject_type) {
            self::ATTENDANCE_MONTH => new self(
                title: '月次勤怠が差戻されました',
                summary: self::yearMonth($workflowRequest)." の月次勤怠が差し戻されました: {$comment}",
                detailUrl: FrontendUrl::path('/attendance/months/'.self::yearMonth($workflowRequest)),
            ),
            self::EXPENSE_CLAIM => new self(
                title: '経費精算が差戻されました',
                summary: '「'.self::claimTitle($workflowRequest)."」の経費精算が差し戻されました: {$comment}",
                detailUrl: FrontendUrl::path('/expenses/'.$workflowRequest->subject_id),
            ),
            self::PAID_LEAVE_REQUEST => new self(
                title: '有給休暇申請が差戻されました',
                summary: "有給休暇申請が差し戻されました: {$comment}",
                detailUrl: FrontendUrl::path('/paid-leave/requests'),
            ),
            self::SPECIAL_LEAVE_REQUEST => new self(
                title: '特別休暇申請が差戻されました',
                summary: "特別休暇申請が差し戻されました: {$comment}",
                detailUrl: FrontendUrl::path('/special-leave/requests'),
            ),
            self::SHIFT_SWAP_REQUEST => new self(
                title: '振替休日申請が差戻されました',
                summary: "振替休日申請が差し戻されました: {$comment}",
                detailUrl: FrontendUrl::path('/shift-swap/requests'),
            ),
            default => new self(
                title: '差戻し',
                summary: "「{$workflowRequest->title}」が差し戻されました: {$comment}",
                detailUrl: FrontendUrl::path("/requests/{$workflowRequest->id}"),
            ),
        };
    }

    /**
     * 正データ(attendance_months)が消えている場合でも通知自体は送れるよう、
     * workflow_requests.titleへフォールバックする。
     */
    private static function yearMonth(WorkflowRequest $workflowRequest): string
    {
        return AttendanceMonth::query()->find($workflowRequest->subject_id)?->year_month
            ?? $workflowRequest->title;
    }

    private static function claimTitle(WorkflowRequest $workflowRequest): string
    {
        return ExpenseClaim::query()->find($workflowRequest->subject_id)?->title
            ?? $workflowRequest->title;
    }
}
