<?php

namespace Tests\Feature\SpecialLeave;

use App\Models\SpecialLeaveGrantRule;
use App\Models\SpecialLeaveType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 特別休暇付与ルールの対象社員一覧 (docs/changesets/20260904-paid-leave-auto-grant-per-user-toggle/spec.md)。
 */
class SpecialLeaveGrantRuleTargetUsersTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_all_users_with_hire_date_and_their_toggle_state(): void
    {
        $type = SpecialLeaveType::query()->create(['name' => '誕生日休暇', 'is_active' => true]);
        $rule = SpecialLeaveGrantRule::query()->create([
            'special_leave_type_id' => $type->id,
            'name' => '全社員', 'work_style_id' => null, 'min_attendance_rate' => 80,
            'first_grant_after_months' => 0, 'grant_cycle_months' => 12, 'is_active' => true,
        ]);
        $eligible = User::factory()->create(['name' => '対象太郎', 'hire_date' => '2024-04-01', 'special_leave_auto_grant_enabled' => false]);
        User::factory()->create(['name' => '未入社花子', 'hire_date' => null]);

        $response = $this->actingAs($eligible)->getJson("/api/special-leave/grant-rules/{$rule->id}/target-users");

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('対象太郎'));
        $this->assertFalse($names->contains('未入社花子'));
        $entry = collect($response->json('data'))->firstWhere('id', $eligible->id);
        $this->assertFalse($entry['special_leave_auto_grant_enabled']);
    }
}
