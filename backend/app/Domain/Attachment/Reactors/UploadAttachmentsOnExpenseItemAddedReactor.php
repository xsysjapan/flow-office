<?php

namespace App\Domain\Attachment\Reactors;

use App\Domain\Attachment\Commands\UploadAttachment;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\ExpenseClaim\Events\ExpenseItemAdded;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

/**
 * 経費の明細の追加(expense_claim.item_added)に添付の参照が付いているとき、添付の文脈で添付を記録する
 * (原則15・17。明細と添付を1回の操作・1トランザクションで記録する)。
 * 添付のIDは入口が決めており、既にあれば何もしない(`UploadAttachment`のviaReactor)。
 */
class UploadAttachmentsOnExpenseItemAddedReactor extends Reactor
{
    public function __construct(private readonly CommandBus $commandBus) {}

    public function onExpenseItemAdded(ExpenseItemAdded $event): void
    {
        foreach ($event->attachments as $reference) {
            $this->commandBus->dispatch(new UploadAttachment(
                ownerTypeAlias: 'expense_item',
                ownerId: $event->itemId,
                uploadedByUserId: $reference['uploadedByUserId'],
                fileName: $reference['fileName'],
                storedPath: $reference['storedPath'],
                mimeType: $reference['mimeType'],
                fileSize: (int) $reference['fileSize'],
                attachmentId: $reference['attachmentId'],
                viaReactor: true,
            ));
        }
    }
}
