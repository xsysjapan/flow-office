<?php

namespace Tests\Feature\BackOffice;

use App\Models\ExpenseCategory;
use App\Models\ExpenseClaim;
use App\Models\ExpenseItem;
use App\Models\ExternalAccountMapping;
use App\Models\ExternalEmployeeMapping;
use App\Models\ExternalIntegrationConnection;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

/**
 * フェーズ3: 経費申請(承認済み)の確定データをfreee/moneyforward等の外部APIへ送信する
 * (docs/30-usecases-expense.md UC-X012)。勤怠側(AttendanceExternalApiPublishTest)と
 * 同じ認可基盤(AuthStrategy/ExternalApiPublisher/ExternalIntegrationConnection)を使う。
 */
class ExpenseExternalApiPublishTest extends TestCase
{
    use RefreshDatabase;

    private function createApprovedClaim(User $applicant, string $accountCode = '7101', string $taxCategory = '課税仕入10%'): ExpenseClaim
    {
        $category = ExpenseCategory::query()->create([
            'code' => 'transportation', 'name' => '交通費', 'evidence_type_default' => 'fact_reference_available',
            'account_code' => $accountCode, 'tax_category' => $taxCategory,
        ]);

        $claim = ExpenseClaim::query()->create([
            'employee_id' => $applicant->id, 'period_from' => '2026-06-01', 'period_to' => '2026-06-30',
            'status' => 'approved', 'total_amount' => 3400, 'title' => '6月分交通費',
            'approved_at' => '2026-06-30 10:00:00',
        ]);
        ExpenseItem::query()->create([
            'claim_id' => $claim->id, 'category_id' => $category->id, 'amount' => 3400,
            'reimbursement_amount' => 3400, 'evidence_type' => 'fact_reference_available',
            'usage_date' => '2026-06-15', 'description' => '電車代',
        ]);

        return $claim;
    }

    public function test_freee_publish_sends_payload_and_records_stored_event(): void
    {
        Http::fake([
            'https://api.freee.co.jp/*' => Http::response(['status' => 'ok'], 200),
        ]);

        $staff = User::factory()->create();
        $this->assignRole($staff, Role::query()->create(['code' => Role::ACCOUNTING_STAFF, 'name' => '経理担当者']));
        $applicant = User::factory()->create(['name' => '申請者太郎']);
        $claim = $this->createApprovedClaim($applicant);

        ExternalIntegrationConnection::query()->create([
            'id' => (string) Str::uuid(),
            'provider' => ExternalIntegrationConnection::PROVIDER_FREEE,
            'auth_type' => ExternalIntegrationConnection::AUTH_TYPE_OAUTH2,
            'status' => ExternalIntegrationConnection::STATUS_ACTIVE,
            'access_token' => 'valid-access-token',
            'refresh_token' => 'refresh-token',
            'token_expires_at' => now()->addHour(),
            'connected_at' => now(),
        ]);

        ExternalEmployeeMapping::query()->create([
            'id' => (string) Str::uuid(),
            'provider' => ExternalIntegrationConnection::PROVIDER_FREEE,
            'user_id' => $applicant->id,
            'external_employee_code' => 'FREEE-001',
        ]);

        $response = $this->actingAs($staff)->postJson('/api/exports/expenses/external-publish', [
            'year_month' => ['2026-06'],
            'provider' => 'freee',
        ]);

        $response->assertSuccessful();
        $response->assertJson([
            'provider' => 'freee',
            'successes' => [[
                'employee_id' => $applicant->id,
                'expense_claim_id' => $claim->id,
                'external_employee_code' => 'FREEE-001',
            ]],
            'failures' => [],
        ]);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.freee.co.jp/api/1/deals'
                && $request->hasHeader('Authorization', 'Bearer valid-access-token')
                && $request['employee_code'] === 'FREEE-001'
                && $request['expense_application_line']['details'][0]['account_item_code'] === '7101'
                && $request['expense_application_line']['details'][0]['tax_code'] === '課税仕入10%';
        });

        $this->assertSame(
            1,
            EloquentStoredEvent::query()->where('event_class', 'external_integration.published')->count(),
        );
    }

    /**
     * MoneyForwardクラウド経費APIは仕訳を直接受け取らず、経費明細(ex_transaction)を
     * office_member単位で1件ずつ作成するモデルであることが判明したため
     * (docs/notes/moneyforward-api-investigation.md)、ex_transactions作成APIへ送信することを
     * 検証する。account_code/tax_categoryはコードのままでは送れないため、external_account_mappings
     * 経由でMoneyForward内部ID(ex_item_id/dr_excise_id)へ変換されることも合わせて検証する。
     */
    public function test_moneyforward_publish_sends_ex_transaction_and_records_stored_event(): void
    {
        Http::fake([
            'https://expense.moneyforward.com/api/external/v1/offices/*/office_members/*/ex_transactions' => Http::response(['id' => 'extx-1'], 201),
        ]);

        $staff = User::factory()->create();
        $this->assignRole($staff, Role::query()->create(['code' => Role::ACCOUNTING_STAFF, 'name' => '経理担当者']));
        $applicant = User::factory()->create(['name' => '申請者花子']);
        $this->createApprovedClaim($applicant);

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

        ExternalIntegrationConnection::query()->create([
            'id' => (string) Str::uuid(),
            'provider' => ExternalIntegrationConnection::PROVIDER_MONEYFORWARD,
            'external_office_id' => 'office-1',
            'auth_type' => ExternalIntegrationConnection::AUTH_TYPE_API_KEY,
            'status' => ExternalIntegrationConnection::STATUS_ACTIVE,
            'api_key' => 'mf-secret-key',
            'connected_at' => now(),
        ]);

        ExternalEmployeeMapping::query()->create([
            'id' => (string) Str::uuid(),
            'provider' => ExternalIntegrationConnection::PROVIDER_MONEYFORWARD,
            'user_id' => $applicant->id,
            'external_employee_code' => 'member-002',
        ]);

        $response = $this->actingAs($staff)->postJson('/api/exports/expenses/external-publish', [
            'year_month' => ['2026-06'],
            'provider' => 'moneyforward',
        ]);

        $response->assertSuccessful();
        $response->assertJsonCount(1, 'successes');
        $response->assertJsonCount(0, 'failures');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://expense.moneyforward.com/api/external/v1/offices/office-1/office_members/member-002/ex_transactions'
                && $request->hasHeader('X-Api-Key', 'mf-secret-key')
                && $request['value'] === 3400
                && $request['ex_item_id'] === 'mf-ex-item-7101'
                && $request['dr_excise_id'] === 'mf-dr-excise-10';
        });

        Http::assertNotSent(function ($request) {
            return str_contains($request->url(), 'upload_receipt');
        });

        $this->assertSame(
            1,
            EloquentStoredEvent::query()->where('event_class', 'external_integration.published')->count(),
        );
    }

    public function test_unapproved_claim_is_excluded_from_publish_target(): void
    {
        Http::fake();

        $staff = User::factory()->create();
        $this->assignRole($staff, Role::query()->create(['code' => Role::ACCOUNTING_STAFF, 'name' => '経理担当者']));
        $applicant = User::factory()->create(['name' => '未承認申請者']);

        $category = ExpenseCategory::query()->create([
            'code' => 'transportation', 'name' => '交通費', 'evidence_type_default' => 'fact_reference_available',
        ]);
        ExpenseClaim::query()->create([
            'employee_id' => $applicant->id, 'period_from' => '2026-06-01', 'period_to' => '2026-06-30',
            'status' => 'in_review', 'total_amount' => 3400, 'title' => '未承認分',
        ]);

        ExternalIntegrationConnection::query()->create([
            'id' => (string) Str::uuid(),
            'provider' => ExternalIntegrationConnection::PROVIDER_FREEE,
            'auth_type' => ExternalIntegrationConnection::AUTH_TYPE_OAUTH2,
            'status' => ExternalIntegrationConnection::STATUS_ACTIVE,
            'access_token' => 'token',
            'token_expires_at' => now()->addHour(),
        ]);
        ExternalEmployeeMapping::query()->create([
            'id' => (string) Str::uuid(),
            'provider' => ExternalIntegrationConnection::PROVIDER_FREEE,
            'user_id' => $applicant->id,
            'external_employee_code' => 'FREEE-999',
        ]);
        unset($category);

        $response = $this->actingAs($staff)->postJson('/api/exports/expenses/external-publish', [
            'year_month' => ['2026-06'],
            'provider' => 'freee',
        ]);

        $response->assertSuccessful();
        $response->assertJsonCount(0, 'successes');
        $response->assertJsonCount(0, 'failures');

        Http::assertNothingSent();
        $this->assertSame(
            0,
            EloquentStoredEvent::query()->where('event_class', 'external_integration.published')->count(),
        );
    }

    public function test_missing_employee_mapping_is_reported_as_a_failure_without_sending(): void
    {
        Http::fake();

        $staff = User::factory()->create();
        $this->assignRole($staff, Role::query()->create(['code' => Role::ACCOUNTING_STAFF, 'name' => '経理担当者']));
        $applicant = User::factory()->create(['name' => 'マッピング未登録申請者']);
        $this->createApprovedClaim($applicant);

        ExternalIntegrationConnection::query()->create([
            'id' => (string) Str::uuid(),
            'provider' => ExternalIntegrationConnection::PROVIDER_FREEE,
            'auth_type' => ExternalIntegrationConnection::AUTH_TYPE_OAUTH2,
            'status' => ExternalIntegrationConnection::STATUS_ACTIVE,
            'access_token' => 'token',
            'token_expires_at' => now()->addHour(),
        ]);

        $response = $this->actingAs($staff)->postJson('/api/exports/expenses/external-publish', [
            'year_month' => ['2026-06'],
            'provider' => 'freee',
        ]);

        $response->assertSuccessful();
        $response->assertJsonCount(0, 'successes');
        $response->assertJsonCount(1, 'failures');
        $response->assertJsonPath('failures.0.reason', 'employee_mapping_missing');

        Http::assertNothingSent();
        $this->assertSame(
            0,
            EloquentStoredEvent::query()->where('event_class', 'external_integration.published')->count(),
        );
    }

    public function test_employee_without_export_permission_is_forbidden(): void
    {
        $employee = User::factory()->create();

        $response = $this->actingAs($employee)->postJson('/api/exports/expenses/external-publish', [
            'year_month' => ['2026-06'],
            'provider' => 'freee',
        ]);

        $response->assertForbidden();
    }
}
