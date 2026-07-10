<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\StoredEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * UC-M003: 監査ログを確認する。
 */
class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_search_audit_log_by_aggregate_type(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        StoredEvent::query()->create([
            'event_id' => (string) Str::uuid(),
            'aggregate_type' => 'workflow_request',
            'aggregate_id' => '1',
            'version' => 1,
            'event_type' => 'workflow_request.drafted',
            'payload' => ['applicant_user_id' => 42],
            'occurred_at' => now(),
        ]);
        StoredEvent::query()->create([
            'event_id' => (string) Str::uuid(),
            'aggregate_type' => 'attendance_day',
            'aggregate_id' => '1',
            'version' => 1,
            'event_type' => 'attendance.clocked_in',
            'payload' => ['user_id' => 99],
            'occurred_at' => now(),
        ]);

        $response = $this->actingAs($admin)->getJson('/api/audit-log?aggregate_type=workflow_request');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('workflow_request.drafted', $data[0]['event_type']);
    }

    public function test_non_admin_cannot_view_audit_log(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee)->getJson('/api/audit-log')->assertForbidden();
    }
}
