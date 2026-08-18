<?php

namespace Tests\Feature\PaidLeave;

use App\Models\PaidLeaveGrant;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 管理者による有給付与の取消。未消化の付与のみ取り消せる。
 */
class PaidLeaveGrantRevocationTest extends TestCase
{
    use RefreshDatabase;

    private function grantFor(User $hr, User $employee): string
    {
        return $this->actingAs($hr)->postJson('/api/paid-leave/grants', [
            'user_id' => $employee->id,
            'granted_on' => '2026-07-01',
            'expires_on' => '2028-06-30',
            'granted_days' => 10,
            'grant_reason' => '初回付与',
        ])->assertCreated()->json('id');
    }

    public function test_hr_staff_can_revoke_an_unused_grant(): void
    {
        $hr = User::factory()->create();
        $this->assignRole($hr, Role::query()->create(['code' => Role::HR_STAFF, 'name' => '人事担当者']));
        $employee = User::factory()->create();

        $grantId = $this->grantFor($hr, $employee);

        $response = $this->actingAs($hr)->postJson("/api/paid-leave/grants/{$grantId}/revoke", [
            'reason' => '入力誤り',
        ]);

        $response->assertOk();
        $this->assertSame('revoked', $response->json('status'));

        $grant = PaidLeaveGrant::query()->findOrFail($grantId);
        $this->assertSame('revoked', $grant->status);
        $this->assertNotNull($grant->revoked_at);
        $this->assertSame($hr->id, $grant->revoked_by_user_id);
        $this->assertSame('入力誤り', $grant->revoke_reason);
    }

    public function test_revocation_is_blocked_once_any_days_have_been_used(): void
    {
        $hr = User::factory()->create();
        $this->assignRole($hr, Role::query()->create(['code' => Role::HR_STAFF, 'name' => '人事担当者']));
        $employee = User::factory()->create();

        $grantId = $this->grantFor($hr, $employee);

        // 消化済みとして直接更新する(消化フローは他ドメインのため、Projectionを直接
        // 書き換えるのはこのテスト検証のみの目的で許容する)。
        PaidLeaveGrant::query()->whereKey($grantId)->update(['used_days' => 1, 'remaining_days' => 9]);

        $response = $this->actingAs($hr)->postJson("/api/paid-leave/grants/{$grantId}/revoke");

        $response->assertStatus(422);
        $this->assertSame('active', PaidLeaveGrant::query()->findOrFail($grantId)->status);
    }

    public function test_employee_cannot_revoke_a_grant(): void
    {
        $hr = User::factory()->create();
        $this->assignRole($hr, Role::query()->create(['code' => Role::HR_STAFF, 'name' => '人事担当者']));
        $employee = User::factory()->create();

        $grantId = $this->grantFor($hr, $employee);

        $this->actingAs($employee)->postJson("/api/paid-leave/grants/{$grantId}/revoke")->assertForbidden();
    }
}
