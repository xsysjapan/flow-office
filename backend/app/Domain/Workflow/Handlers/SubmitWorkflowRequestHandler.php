<?php

namespace App\Domain\Workflow\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\Workflow\Aggregates\WorkflowRequestAggregate;
use App\Domain\Workflow\Commands\SubmitWorkflowRequest;
use App\Domain\Workflow\Support\WorkflowRequestNotificationContent;
use App\Jobs\SendNotificationJob;
use App\Models\User;
use App\Models\WorkflowRequest;
use App\Models\WorkflowRequestStatus;
use Illuminate\Validation\ValidationException;

/**
 * @implements CommandHandler<SubmitWorkflowRequest>
 */
class SubmitWorkflowRequestHandler implements CommandHandler
{
    public function handle(Command $command): WorkflowRequest
    {
        assert($command instanceof SubmitWorkflowRequest);

        $workflowRequest = WorkflowRequest::query()->with('requestType')->findOrFail($command->workflowRequestId);

        if ($workflowRequest->applicant_user_id !== $command->submittedByUserId) {
            throw new DomainRuleException('自分が作成した申請のみ申請できます。');
        }

        if (! in_array($workflowRequest->status, [WorkflowRequestStatus::DRAFT, WorkflowRequestStatus::RETURNED], true)) {
            throw new DomainRuleException('この申請は現在のステータスからは提出できません。');
        }

        // subject_type付きの行(月次勤怠・経費精算)は申請種別マスタを持たない(requestTypeがnull)
        // ため、申請種別由来の必須チェックは行わない。承認者の必須チェックは種別を問わず行う。
        if ($workflowRequest->requestType?->requires_attachment && ! $workflowRequest->attachments()->exists()) {
            throw new DomainRuleException('この申請種別は添付ファイルが必須です。');
        }

        $approverUserId = $command->approverUserId ?? $workflowRequest->approver_user_id;
        if ($approverUserId === null) {
            throw ValidationException::withMessages(['approver_user_id' => ['承認者を指定してください。']]);
        }

        // 承認者に申請者本人を指定した場合、承認待ちのまま自分の承認操作を待つ状態にはせず、
        // 提出と同時に承認をスキップして確定させる(「承認不要」設定とは別物で、承認ルート
        // 自体はあるが実質的な承認者がいないケースを指す)。approvedByUserIdには申請者自身の
        // IDをそのまま使う(nullは経費精算・月次勤怠等のapproval_skip_threshold/
        // requires_approval=falseによる自動承認の専用センチネルとして各Reactorが「対向ドメイン側で
        // 既に承認済み」の判定に使っているため、ここで転用すると二重承認の抑止が誤作動する)。
        $isSelfApproval = $approverUserId === $command->submittedByUserId;

        $aggregate = WorkflowRequestAggregate::retrieve($workflowRequest->id)
            ->submit(approverUserId: $approverUserId, submittedByUserId: $command->submittedByUserId);

        if ($isSelfApproval) {
            $aggregate->approve(approvedByUserId: $command->submittedByUserId);
        }

        $aggregate->persist();

        $workflowRequest->refresh();

        if (! $isSelfApproval) {
            $approver = User::find($approverUserId);
            if ($approver !== null) {
                $content = WorkflowRequestNotificationContent::forSubmitted($workflowRequest);

                SendNotificationJob::enqueue(
                    recipient: $approver,
                    title: $content->title,
                    summary: $content->summary,
                    detailUrl: $content->detailUrl,
                );
            }
        }

        return $workflowRequest;
    }
}
