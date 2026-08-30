<?php

namespace Tests\Feature\Asset;

use App\Domain\Asset\Commands\LendAsset;
use App\Domain\Asset\Commands\RegisterAsset;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\Workflow\Commands\ApproveWorkflowRequest;
use App\Domain\Workflow\Commands\CancelWorkflowRequest;
use App\Domain\Workflow\Commands\DraftWorkflowRequest;
use App\Domain\Workflow\Commands\RejectWorkflowRequest;
use App\Domain\Workflow\Commands\SubmitWorkflowRequest;
use App\Models\Asset;
use App\Models\AssetLendingMethod;
use App\Models\AssetLendingStatus;
use App\Models\AssetLoanRequest;
use App\Models\AssetLoanRequestStatus;
use App\Models\AssetManagementType;
use App\Models\RequestType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 備品貸出申請(request_types.code=asset_loan)の申請→承認→貸与フロー、および
 * asset_loan_requestsプロジェクションの反映(spec 論点1・論点2)。
 */
class AssetLoanRequestTest extends TestCase
{
    use RefreshDatabase;

    private function bus(): CommandBus
    {
        return app(CommandBus::class);
    }

    private function registerApprovalAsset(User $backoffice): Asset
    {
        return $this->bus()->dispatch(new RegisterAsset(
            assetNo: 'EQ-'.uniqid(),
            name: 'タブレット',
            category: 'PC',
            serialNumber: null,
            managementType: AssetManagementType::LENDING,
            lendingMethod: AssetLendingMethod::APPROVAL,
            defaultLocationText: null,
            notes: null,
            registeredByUserId: $backoffice->id,
        ));
    }

    /**
     * @return array{applicant: User, approver: User, asset: Asset, workflowRequestId: string}
     */
    private function submitLoanRequest(?string $purpose = 'リモート会議用'): array
    {
        RequestType::query()->firstOrCreate(['code' => 'asset_loan'], [
            'name' => '備品貸出申請',
            'form_schema' => [
                ['key' => 'asset_id', 'label' => '対象備品', 'type' => 'uuid', 'required' => true],
                ['key' => 'purpose', 'label' => '利用目的', 'type' => 'text', 'required' => false],
            ],
            'requires_backoffice_task' => false,
            'is_active' => true,
        ]);

        $backoffice = User::factory()->create();
        $applicant = User::factory()->create();
        $approver = User::factory()->create();
        $asset = $this->registerApprovalAsset($backoffice);

        $draft = $this->bus()->dispatch(new DraftWorkflowRequest(
            requestTypeCode: 'asset_loan',
            applicantUserId: $applicant->id,
            title: 'タブレット貸出申請',
            formData: ['asset_id' => $asset->id, 'purpose' => $purpose],
            approverUserId: $approver->id,
        ));

        $this->bus()->dispatch(new SubmitWorkflowRequest(
            workflowRequestId: $draft->id,
            submittedByUserId: $applicant->id,
            approverUserId: $approver->id,
        ));

        return ['applicant' => $applicant, 'approver' => $approver, 'asset' => $asset, 'workflowRequestId' => $draft->id];
    }

    public function test_submitting_creates_a_pending_asset_loan_request_projection_row(): void
    {
        ['applicant' => $applicant, 'approver' => $approver, 'asset' => $asset, 'workflowRequestId' => $id] = $this->submitLoanRequest();

        $loanRequest = AssetLoanRequest::query()->findOrFail($id);
        $this->assertSame(AssetLoanRequestStatus::PENDING, $loanRequest->status);
        $this->assertSame($asset->id, $loanRequest->asset_id);
        $this->assertSame($applicant->id, $loanRequest->applicant_user_id);
        $this->assertSame($approver->id, $loanRequest->approver_user_id);
        $this->assertSame('リモート会議用', $loanRequest->purpose);
        $this->assertNotNull($loanRequest->submitted_at);
    }

    public function test_approving_updates_the_projection_but_does_not_lend_the_asset(): void
    {
        ['approver' => $approver, 'asset' => $asset, 'workflowRequestId' => $id] = $this->submitLoanRequest();

        $this->bus()->dispatch(new ApproveWorkflowRequest($id, $approver->id));

        $loanRequest = AssetLoanRequest::query()->findOrFail($id);
        $this->assertSame(AssetLoanRequestStatus::APPROVED, $loanRequest->status);
        $this->assertNotNull($loanRequest->approved_at);

        // 承認だけでは資産はavailableのまま(spec 論点1)。
        $asset->refresh();
        $this->assertSame(AssetLendingStatus::AVAILABLE, $asset->lending_status);
    }

    public function test_cannot_lend_an_approval_method_asset_without_an_approved_loan_request(): void
    {
        $backoffice = User::factory()->create();
        $borrower = User::factory()->create();
        $asset = $this->registerApprovalAsset($backoffice);

        $this->expectException(DomainRuleException::class);
        $this->bus()->dispatch(new LendAsset(
            assetId: $asset->id,
            borrowerUserId: $borrower->id,
            lentByUserId: $backoffice->id,
        ));
    }

    public function test_cannot_lend_before_the_loan_request_is_approved(): void
    {
        ['applicant' => $applicant, 'asset' => $asset, 'workflowRequestId' => $id] = $this->submitLoanRequest();
        $backoffice = User::factory()->create();

        $this->expectException(DomainRuleException::class);
        $this->bus()->dispatch(new LendAsset(
            assetId: $asset->id,
            borrowerUserId: $applicant->id,
            lentByUserId: $backoffice->id,
            expectedReturnAt: null,
            loanRequestId: $id,
        ));
    }

    public function test_an_approved_loan_request_allows_lending_and_is_marked_lent(): void
    {
        ['applicant' => $applicant, 'approver' => $approver, 'asset' => $asset, 'workflowRequestId' => $id] = $this->submitLoanRequest();
        $backoffice = User::factory()->create();

        $this->bus()->dispatch(new ApproveWorkflowRequest($id, $approver->id));

        $result = $this->bus()->dispatch(new LendAsset(
            assetId: $asset->id,
            borrowerUserId: $applicant->id,
            lentByUserId: $backoffice->id,
            expectedReturnAt: null,
            loanRequestId: $id,
        ));

        $this->assertSame(AssetLendingStatus::LOANED, $result->lending_status);

        $loanRequest = AssetLoanRequest::query()->findOrFail($id);
        $this->assertSame(AssetLoanRequestStatus::LENT, $loanRequest->status);
        $this->assertNotNull($loanRequest->lent_at);
    }

    public function test_a_loan_request_for_a_different_asset_cannot_be_used(): void
    {
        ['applicant' => $applicant, 'approver' => $approver, 'workflowRequestId' => $id] = $this->submitLoanRequest();
        $backoffice = User::factory()->create();
        $this->bus()->dispatch(new ApproveWorkflowRequest($id, $approver->id));

        $otherAsset = $this->registerApprovalAsset($backoffice);

        $this->expectException(DomainRuleException::class);
        $this->bus()->dispatch(new LendAsset(
            assetId: $otherAsset->id,
            borrowerUserId: $applicant->id,
            lentByUserId: $backoffice->id,
            expectedReturnAt: null,
            loanRequestId: $id,
        ));
    }

    public function test_the_approver_can_reject_the_loan_request(): void
    {
        ['approver' => $approver, 'workflowRequestId' => $id] = $this->submitLoanRequest();

        $this->bus()->dispatch(new RejectWorkflowRequest($id, $approver->id, '在庫不足のため'));

        $loanRequest = AssetLoanRequest::query()->findOrFail($id);
        $this->assertSame(AssetLoanRequestStatus::REJECTED, $loanRequest->status);
        $this->assertSame('在庫不足のため', $loanRequest->rejection_reason);
        $this->assertNotNull($loanRequest->rejected_at);
    }

    public function test_the_applicant_can_withdraw_a_pending_loan_request(): void
    {
        ['applicant' => $applicant, 'workflowRequestId' => $id] = $this->submitLoanRequest();

        $this->bus()->dispatch(new CancelWorkflowRequest($id, $applicant->id, '不要になったため'));

        $loanRequest = AssetLoanRequest::query()->findOrFail($id);
        $this->assertSame(AssetLoanRequestStatus::WITHDRAWN, $loanRequest->status);
        $this->assertNotNull($loanRequest->withdrawn_at);
    }

    public function test_deleting_an_asset_with_a_pending_loan_request_is_rejected(): void
    {
        ['applicant' => $applicant, 'asset' => $asset] = $this->submitLoanRequest();
        $backoffice = User::factory()->create();

        $this->expectException(DomainRuleException::class);
        $this->bus()->dispatch(new \App\Domain\Asset\Commands\DeleteAsset($asset->id, $backoffice->id));
    }
}
