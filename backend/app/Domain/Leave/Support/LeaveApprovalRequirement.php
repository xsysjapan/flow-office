<?php

namespace App\Domain\Leave\Support;

use App\Domain\Workflow\Support\WorkflowRequestNotificationContent;
use App\Models\SystemSetting;
use InvalidArgumentException;

/**
 * 休暇の種類(有給・特別休暇・代休・振替休日)ごとに、全体の設定(system_settings.*_requires_approval)から
 * 申請に承認が必要かを返す判定クラス。申請の入口は設定で分かれる(承認が必要ならワークフローの下書き、
 * 不要なら直接の申請)。呼び出し側が設定を見て入口を選び、各Handlerが同じ判定で設定と違う入口を拒否する。
 */
final class LeaveApprovalRequirement
{
    public const PAID_LEAVE = 'paid_leave';

    public const SPECIAL_LEAVE = 'special_leave';

    public const COMPENSATORY_LEAVE = 'compensatory_leave';

    public const SHIFT_SWAP = 'shift_swap';

    private const LABELS = [
        self::PAID_LEAVE => '有給休暇',
        self::SPECIAL_LEAVE => '特別休暇',
        self::COMPENSATORY_LEAVE => '代休',
        self::SHIFT_SWAP => '振替休日',
    ];

    /** ワークフローのsubject_type → 休暇の種類。休暇・振替休日以外はnull。 */
    private const SUBJECT_TYPE_KINDS = [
        WorkflowRequestNotificationContent::PAID_LEAVE_REQUEST => self::PAID_LEAVE,
        WorkflowRequestNotificationContent::SPECIAL_LEAVE_REQUEST => self::SPECIAL_LEAVE,
        WorkflowRequestNotificationContent::COMPENSATORY_LEAVE_REQUEST => self::COMPENSATORY_LEAVE,
        WorkflowRequestNotificationContent::SHIFT_SWAP_REQUEST => self::SHIFT_SWAP,
    ];

    /** 申請に承認が必要か(全体の設定)。 */
    public function isRequired(string $kind): bool
    {
        $settings = SystemSetting::current();

        return match ($kind) {
            self::PAID_LEAVE => (bool) $settings->paid_leave_requires_approval,
            self::SPECIAL_LEAVE => (bool) $settings->special_leave_requires_approval,
            self::COMPENSATORY_LEAVE => (bool) $settings->compensatory_leave_requires_approval,
            self::SHIFT_SWAP => (bool) $settings->shift_swap_requires_approval,
            default => throw new InvalidArgumentException("不明な休暇の種類です: {$kind}"),
        };
    }

    /** ワークフローのsubject_typeに対応する休暇の種類。休暇・振替休日以外はnull。 */
    public function kindForSubjectType(?string $subjectType): ?string
    {
        return $subjectType === null ? null : (self::SUBJECT_TYPE_KINDS[$subjectType] ?? null);
    }

    public function label(string $kind): string
    {
        return self::LABELS[$kind] ?? throw new InvalidArgumentException("不明な休暇の種類です: {$kind}");
    }

    /** 直接の申請(利用者の操作)の入口で、承認が必要な設定なら拒否する文言を返す(拒否しないならnull)。 */
    public function directRequestRejection(string $kind): ?string
    {
        return $this->isRequired($kind)
            ? $this->label($kind).'は承認が必要です。申請から承認者を指定して申請してください。'
            : null;
    }

    /** ワークフローの下書きの入口で、承認が不要な設定なら拒否する文言を返す(休暇・振替休日以外・拒否しないならnull)。 */
    public function workflowDraftRejection(?string $subjectType): ?string
    {
        $kind = $this->kindForSubjectType($subjectType);

        if ($kind === null || $this->isRequired($kind)) {
            return null;
        }

        return $this->label($kind).'は承認が不要です。直接申請してください。';
    }
}
