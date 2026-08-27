<?php

namespace App\Domain\Export\Services\ExpenseApi;

use App\Models\Attachment;
use App\Models\ExpenseClaim;
use App\Models\ExpenseItem;
use App\Models\ExternalAccountMapping;
use App\Models\ExternalIntegrationConnection;

/**
 * マネーフォワードクラウド経費APIのペイロード構造。
 *
 * 実際のAPI(docs/notes/moneyforward-api-investigation.mdで一次情報を確認済み)は
 * 「仕訳(journal)を直接送る」のではなく、経費明細(ex_transaction)を1件ずつ
 * `POST /api/external/v1/offices/{office_id}/office_members/{office_member_id}/ex_transactions`
 * で作成するモデルであるため、ExpenseItem 1件につき ex_transaction 1件を組み立てる。
 * 領収書が添付されている場合は upload_receipt で先にアップロードし、その結果を
 * `receipt_input` として ex_transaction 側へ紐付ける必要があるため、ここでは
 * 領収書の添付情報(attachment id・保存パス・ファイル名・MIMEタイプ)のみを payload に含め、
 * 実際の2段階呼び出しは MoneyForwardExpensePublisher (Services/Publishers) が行う。
 *
 * `ex_item_id`(経費科目id)・`dr_excise_id`(税区分id)はコードではなくMoneyForward内部の
 * 管理IDのため、`expense_categories.account_code`/`tax_category`をそのまま送らず、
 * `external_account_mappings`(ExternalAccountMapping)経由でMoneyForward内部IDへ変換する。
 * マッピングが存在しない科目・税区分はnullのまま送る(MoneyForward側でエラーになるが、
 * 存在しないコードを捏造して送るよりは安全なため)。
 *
 * docs/30-usecases-expense.md UC-X012参照。
 */
class MoneyForwardExpenseApiPayloadBuilder implements ExpenseApiPayloadBuilder
{
    public function key(): string
    {
        return ExternalIntegrationConnection::PROVIDER_MONEYFORWARD;
    }

    public function build(ExpenseClaim $claim, string $externalEmployeeCode): array
    {
        $exItemMap = ExternalAccountMapping::mapFor($this->key(), ExternalAccountMapping::TYPE_EX_ITEM);
        $drExciseMap = ExternalAccountMapping::mapFor($this->key(), ExternalAccountMapping::TYPE_DR_EXCISE);

        return [
            'office_member_id' => $externalEmployeeCode,
            'ex_transactions' => $claim->items->map(function (ExpenseItem $item) use ($exItemMap, $drExciseMap): array {
                $category = $item->category;
                $remarkSource = $item->description ?? $category?->name ?? '';

                return [
                    'source_item_id' => $item->id,
                    'value' => $item->reimbursement_amount,
                    'recognized_at' => $item->usage_date?->format('Y-m-d'),
                    'remark' => mb_substr($remarkSource, 0, 100),
                    'memo' => mb_substr($item->description ?? '', 0, 800),
                    'ex_item_id' => $exItemMap[$category?->account_code ?? ''] ?? null,
                    'dr_excise_id' => $drExciseMap[$category?->tax_category ?? ''] ?? null,
                    'receipt' => $this->receiptAttachment($item),
                ];
            })->all(),
        ];
    }

    /**
     * @return array{attachment_id: string, file_name: string, stored_path: string, mime_type: ?string}|null
     */
    private function receiptAttachment(ExpenseItem $item): ?array
    {
        /** @var Attachment|null $attachment */
        $attachment = $item->relationLoaded('attachments') ? $item->attachments->first() : null;

        if ($attachment === null) {
            return null;
        }

        return [
            'attachment_id' => $attachment->id,
            'file_name' => $attachment->file_name,
            'stored_path' => $attachment->stored_path,
            'mime_type' => $attachment->mime_type,
        ];
    }
}
