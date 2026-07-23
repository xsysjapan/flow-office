<?php

namespace Tests\Feature;

use App\Models\RequestType;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UC-M003: 監査ログを確認する。
 *
 * 監査ログはspatie/laravel-event-sourcingの標準テーブル(stored_events)のみを検索する。
 * 本番リリースは全ドメインの移行完了後を予定しており、移行期間中に限り
 * legacy_stored_events(未移行ドメイン)は監査ログの対象外とする
 * (docs/29-event-sourcing-framework-migration.md参照)。
 */
class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_search_audit_log_by_aggregate_type(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));
        $applicant = User::factory()->create();

        $requestType = RequestType::query()->create([
            'code' => 'expense_reimbursement',
            'name' => '経費精算',
            'form_schema' => [],
            'is_active' => true,
        ]);

        $this->actingAs($applicant)->postJson('/api/workflow-requests', [
            'request_type_code' => $requestType->code,
            'title' => 'タクシー代',
            'form_data' => [],
        ])->assertCreated();

        $matching = $this->actingAs($admin)->getJson('/api/audit-log?aggregate_type=workflow_request');
        $matching->assertOk();
        $data = $matching->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('workflow_request.drafted', $data[0]['event_type']);
        $this->assertSame('タクシー代', $data[0]['payload']['title']);

        $unrelated = $this->actingAs($admin)->getJson('/api/audit-log?aggregate_type=backoffice_task');
        $unrelated->assertOk();
        $this->assertCount(0, $unrelated->json('data'));
    }

    public function test_non_admin_cannot_view_audit_log(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee)->getJson('/api/audit-log')->assertForbidden();
    }
}
