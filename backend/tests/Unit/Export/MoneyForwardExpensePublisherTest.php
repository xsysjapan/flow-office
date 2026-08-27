<?php

namespace Tests\Unit\Export;

use App\Domain\Export\Services\ExpenseApi\MoneyForwardExpenseApiPayloadBuilder;
use App\Domain\Export\Services\ExternalAuth\ApiKeyStrategy;
use App\Domain\Export\Services\Publishers\MoneyForwardExpensePublisher;
use App\Models\Attachment;
use App\Models\ExpenseCategory;
use App\Models\ExpenseClaim;
use App\Models\ExpenseItem;
use App\Models\ExternalAccountMapping;
use App\Models\ExternalIntegrationConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * MoneyForwardクラウド経費APIは、仕訳(journal)を直接送るのではなく経費明細
 * (ex_transaction)を1件ずつ作成するモデルであり、領収書は`upload_receipt`で別途
 * アップロードしてから`ex_transaction`側へ紐付ける2段階呼び出しであることが
 * docs/notes/moneyforward-api-investigation.mdの一次調査で判明した。
 * MoneyForwardExpenseApiPayloadBuilder(ペイロード組み立て)とMoneyForwardExpensePublisher
 * (実際の2段階HTTP呼び出し)を通しで検証する。
 */
class MoneyForwardExpensePublisherTest extends TestCase
{
    use RefreshDatabase;

    public function test_publish_uploads_receipt_then_creates_ex_transaction_for_each_item(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('receipts/receipt-1.png', 'fake-image-bytes');

        Http::fake([
            'https://expense.moneyforward.com/api/external/v1/offices/office-1/office_members/member-1/upload_receipt' => Http::response(['receipt_input' => 'receipt-token-1'], 201),
            'https://expense.moneyforward.com/api/external/v1/offices/office-1/office_members/member-1/ex_transactions' => Http::response(['id' => 'extx-1'], 201),
        ]);

        ExternalAccountMapping::query()->create([
            'id' => (string) Str::uuid(),
            'provider' => ExternalIntegrationConnection::PROVIDER_MONEYFORWARD,
            'mapping_type' => ExternalAccountMapping::TYPE_EX_ITEM,
            'source_code' => '7101',
            'external_id' => 'mf-ex-item-7101',
        ]);
        ExternalAccountMapping::query()->create([
            'id' => (string) Str::uuid(),
            'provider' => ExternalIntegrationConnection::PROVIDER_MONEYFORWARD,
            'mapping_type' => ExternalAccountMapping::TYPE_DR_EXCISE,
            'source_code' => '課税仕入10%',
            'external_id' => 'mf-dr-excise-10',
        ]);

        $applicant = User::factory()->create();
        $category = ExpenseCategory::query()->create([
            'code' => 'transportation', 'name' => '交通費', 'evidence_type_default' => 'fact_reference_available',
            'account_code' => '7101', 'tax_category' => '課税仕入10%',
        ]);
        $claim = ExpenseClaim::query()->create([
            'employee_id' => $applicant->id, 'period_from' => '2026-06-01', 'period_to' => '2026-06-30',
            'status' => 'approved', 'total_amount' => 3400, 'title' => '6月分交通費',
            'approved_at' => '2026-06-30 10:00:00',
        ]);
        $item = ExpenseItem::query()->create([
            'claim_id' => $claim->id, 'category_id' => $category->id, 'amount' => 3400,
            'reimbursement_amount' => 3400, 'evidence_type' => 'fact_reference_available',
            'usage_date' => '2026-06-15', 'description' => '電車代',
        ]);
        Attachment::query()->create([
            'id' => (string) Str::uuid(),
            'owner_type' => 'expense_item',
            'owner_id' => $item->id,
            'uploaded_by' => $applicant->id,
            'file_name' => 'receipt.png',
            'stored_path' => 'receipts/receipt-1.png',
            'mime_type' => 'image/png',
            'file_size' => 17,
        ]);

        $claim->load('items.category', 'items.attachments');

        $payload = (new MoneyForwardExpenseApiPayloadBuilder)->build($claim, 'member-1');

        $this->assertSame('member-1', $payload['office_member_id']);
        $this->assertCount(1, $payload['ex_transactions']);
        $this->assertSame(3400, $payload['ex_transactions'][0]['value']);
        $this->assertSame('mf-ex-item-7101', $payload['ex_transactions'][0]['ex_item_id']);
        $this->assertSame('mf-dr-excise-10', $payload['ex_transactions'][0]['dr_excise_id']);
        $this->assertSame('receipts/receipt-1.png', $payload['ex_transactions'][0]['receipt']['stored_path']);

        $connection = ExternalIntegrationConnection::query()->create([
            'id' => (string) Str::uuid(),
            'provider' => ExternalIntegrationConnection::PROVIDER_MONEYFORWARD,
            'external_office_id' => 'office-1',
            'auth_type' => ExternalIntegrationConnection::AUTH_TYPE_API_KEY,
            'status' => ExternalIntegrationConnection::STATUS_ACTIVE,
            'api_key' => 'mf-secret-key',
            'connected_at' => now(),
        ]);

        $publisher = new MoneyForwardExpensePublisher(
            ExternalIntegrationConnection::PROVIDER_MONEYFORWARD,
            new ApiKeyStrategy($connection),
            $connection->requireExternalOfficeId(),
            'https://expense.moneyforward.com/api/external/v1/offices/{office_id}/office_members/{office_member_id}/ex_transactions',
            'https://expense.moneyforward.com/api/external/v1/offices/{office_id}/office_members/{office_member_id}/upload_receipt',
        );

        $publisher->publish(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'expense.json');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://expense.moneyforward.com/api/external/v1/offices/office-1/office_members/member-1/upload_receipt'
                && $request->hasHeader('X-Api-Key', 'mf-secret-key')
                && $request['file_name'] === 'receipt.png';
        });

        Http::assertSent(function ($request) {
            return $request->url() === 'https://expense.moneyforward.com/api/external/v1/offices/office-1/office_members/member-1/ex_transactions'
                && $request->hasHeader('X-Api-Key', 'mf-secret-key')
                && $request['value'] === 3400
                && $request['ex_item_id'] === 'mf-ex-item-7101'
                && $request['dr_excise_id'] === 'mf-dr-excise-10'
                && $request['receipt_input'] === ['receipt_input' => 'receipt-token-1']
                && ! array_key_exists('receipt', $request->data())
                && ! array_key_exists('source_item_id', $request->data());
        });
    }
}
