<?php

namespace App\Domain\ExpenseClaim\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\ExpenseClaim\Aggregates\ExpenseClaimAggregate;
use App\Domain\ExpenseClaim\Commands\SubmitExpenseClaim;
use App\Models\ExpenseCategory;
use App\Models\ExpenseClaim;
use App\Models\ExpenseClaimStatus;
use App\Models\SystemSetting;

/**
 * UC-X010 手順3〜4 / UC-X011 手順5: 承認者を指定して申請する。
 * expense_categories.approval_skip_threshold または system_settings.expense_claim_requires_approval=false
 * により、明細すべてがしきい値以下、またはシステム全体で承認不要設定の場合は提出と同時に自動承認する。
 * (docs/30-usecases-expense.md「実装上のポイント」)。
 *
 * @implements CommandHandler<SubmitExpenseClaim>
 */
class SubmitExpenseClaimHandler implements CommandHandler
{
    public function handle(Command $command): ExpenseClaim
    {
        assert($command instanceof SubmitExpenseClaim);

        $claim = ExpenseClaim::query()->with('items.category')->findOrFail($command->claimId);

        if ($claim->employee_id !== $command->submittedByUserId) {
            throw new DomainRuleException('自分が作成した経費精算のみ申請できます。');
        }

        if (! in_array($claim->status, ExpenseClaimStatus::editable(), true)) {
            throw new DomainRuleException('この経費精算は現在のステータスからは提出できません。');
        }

        $items = $claim->items;
        if ($items->isEmpty()) {
            throw new DomainRuleException('明細が1件もありません。1件以上の明細を追加してください。');
        }

        foreach ($items as $item) {
            if (
                $item->evidence_type === ExpenseCategory::EVIDENCE_RECEIPT_REQUIRED
                && ! $item->attachments()->exists()
            ) {
                throw new DomainRuleException("明細「{$item->description}」はレシート等の添付が必須です。");
            }
        }

        $autoApprove = ! SystemSetting::current()->expense_claim_requires_approval
            || $items->every(
                fn ($item) => $item->category !== null
                    && $item->category->approval_skip_threshold !== null
                    && $item->amount <= $item->category->approval_skip_threshold
            );

        $aggregate = ExpenseClaimAggregate::retrieve($claim->id)
            ->submit(approverUserId: $command->approverUserId, submittedByUserId: $command->submittedByUserId);

        if ($autoApprove) {
            // このイベントを CreateBackOfficeTaskOnExpenseClaimApprovalReactor が購読し、
            // バックオフィスタスクを自動生成する (UC-X012)。
            $aggregate->approve(approvedByUserId: null);
        }

        $aggregate->persist();

        return $claim->refresh();
    }
}
