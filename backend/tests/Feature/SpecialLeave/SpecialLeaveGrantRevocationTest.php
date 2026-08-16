<?php

namespace Tests\Feature\SpecialLeave;

use App\Models\Role;
use App\Models\SpecialLeaveGrant;
use App\Models\SpecialLeaveType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 管理者による特別休暇付与の取消。PaidLeaveGrantRevocationTestと同じ考え方。
 */
class SpecialLeaveGrantRevocationTest extends TestCase
{
    use RefreshDatabase;

    private function grantFor(User $hr, User $employee): string
    {
        $type = SpecialLeaveType::query()->create(['name' => '慶弔休暇', 'is_active' => true, 'requires_grant' => true]);

        return $this->actingAs($hr)->postJson('/api/special-leave/grants', [
            'user_id' => $employee->id,
            'special_leave_type_id' => $type->id,
            'granted_on' => '2026-07-01',
            'granted_days' => 5,
            'grant_reason' => '慶弔',
        ])->assertCreated()->json('id');
    }

    public function test_hr_staff_can_revoke_an_unused_grant(): void
    {
        $hr = User::factory()->create();
        $this->assignRole($hr, Role::query()->create(['code' => Role::HR_STAFF, 'name' => '人事担当者']));
        $employee = User::factory()->create();

        $grantId = $this->grantFor($hr, $employee);

        $response = $this->actingAs($hr)->postJson("/api/special-leave/grants/{$grantId}/revoke", [
            'reason' => '入力誤り',
        ]);

        $response->assertOk();
        $this->assertSame('revoked', $response->json('status'));
        $this->assertSame('revoked', SpecialLeaveGrant::query()->findOrFail($grantId)->status);
    }

    public function test_revocation_is_blocked_once_any_days_have_been_used(): void
    {
        $hr = User::factory()->create();
        $this->assignRole($hr, Role::query()->create(['code' => Role::HR_STAFF, 'name' => '人事担当者']));
        $employee = User::factory()->create();

        $grantId = $this->grantFor($hr, $employee);
        SpecialLeaveGrant::query()->whereKey($grantId)->update(['used_days' => 1, 'remaining_days' => 4]);

        $this->actingAs($hr)->postJson("/api/special-leave/grants/{$grantId}/revoke")->assertStatus(422);
        $this->assertSame('active', SpecialLeaveGrant::query()->findOrFail($grantId)->status);
    }
}
