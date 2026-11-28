<?php

namespace Tests\Unit\Leave;

use App\Domain\Leave\Support\LeaveApprovalRequirement;
use App\Domain\Workflow\Support\WorkflowRequestNotificationContent;
use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 休暇の種類ごとの承認の要否の判定(全体の設定を読む)と、設定と違う入口の拒否の文言。
 */
class LeaveApprovalRequirementTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string, 1: string, 2: string, 3: string}> 種類・設定の列・subject_type・休暇名 */
    public static function kinds(): array
    {
        return [
            'paid' => [LeaveApprovalRequirement::PAID_LEAVE, 'paid_leave_requires_approval', WorkflowRequestNotificationContent::PAID_LEAVE_REQUEST, '有給休暇'],
            'special' => [LeaveApprovalRequirement::SPECIAL_LEAVE, 'special_leave_requires_approval', WorkflowRequestNotificationContent::SPECIAL_LEAVE_REQUEST, '特別休暇'],
            'compensatory' => [LeaveApprovalRequirement::COMPENSATORY_LEAVE, 'compensatory_leave_requires_approval', WorkflowRequestNotificationContent::COMPENSATORY_LEAVE_REQUEST, '代休'],
            'shift_swap' => [LeaveApprovalRequirement::SHIFT_SWAP, 'shift_swap_requires_approval', WorkflowRequestNotificationContent::SHIFT_SWAP_REQUEST, '振替休日'],
        ];
    }

    #[DataProvider('kinds')]
    public function test_the_requirement_follows_the_setting_of_its_own_kind(string $kind, string $column): void
    {
        $requirement = new LeaveApprovalRequirement;

        SystemSetting::current()->update([$column => true]);
        $this->assertTrue($requirement->isRequired($kind));

        SystemSetting::current()->update([$column => false]);
        $this->assertFalse($requirement->isRequired($kind));
    }

    #[DataProvider('kinds')]
    public function test_the_setting_of_another_kind_does_not_affect_it(string $kind, string $column): void
    {
        $others = array_diff(array_column(self::kinds(), 1), [$column]);
        SystemSetting::current()->update([$column => true] + array_fill_keys($others, false));

        $this->assertTrue((new LeaveApprovalRequirement)->isRequired($kind));
    }

    #[DataProvider('kinds')]
    public function test_a_direct_request_is_rejected_only_when_approval_is_required(string $kind, string $column, string $subjectType, string $label): void
    {
        $requirement = new LeaveApprovalRequirement;

        SystemSetting::current()->update([$column => true]);
        $this->assertSame($label.'は承認が必要です。申請から承認者を指定して申請してください。', $requirement->directRequestRejection($kind));

        SystemSetting::current()->update([$column => false]);
        $this->assertNull($requirement->directRequestRejection($kind));
    }

    #[DataProvider('kinds')]
    public function test_a_workflow_draft_is_rejected_only_when_approval_is_not_required(string $kind, string $column, string $subjectType, string $label): void
    {
        $requirement = new LeaveApprovalRequirement;

        SystemSetting::current()->update([$column => false]);
        $this->assertSame($label.'は承認が不要です。直接申請してください。', $requirement->workflowDraftRejection($subjectType));

        SystemSetting::current()->update([$column => true]);
        $this->assertNull($requirement->workflowDraftRejection($subjectType));
    }

    public function test_a_workflow_draft_of_another_subject_is_never_rejected(): void
    {
        SystemSetting::current()->update([
            'paid_leave_requires_approval' => false, 'special_leave_requires_approval' => false,
            'compensatory_leave_requires_approval' => false, 'shift_swap_requires_approval' => false,
        ]);
        $requirement = new LeaveApprovalRequirement;

        $this->assertNull($requirement->workflowDraftRejection(WorkflowRequestNotificationContent::EXPENSE_CLAIM));
        $this->assertNull($requirement->workflowDraftRejection(WorkflowRequestNotificationContent::ATTENDANCE_MONTH));
        $this->assertNull($requirement->workflowDraftRejection(null));
    }

    public function test_an_unknown_kind_is_an_error(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new LeaveApprovalRequirement)->isRequired('unknown');
    }
}
