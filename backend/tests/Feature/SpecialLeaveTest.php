<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SpecialLeaveType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 特別休暇種別マスタ・残数管理の土台。有給休暇と異なり法定の時効がないため、
 * 失効日を指定しない(無期限の)付与もできることを検証する。
 */
class SpecialLeaveTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsHr(): User
    {
        $hr = User::factory()->create();
        $hr->roles()->attach(Role::query()->create(['code' => Role::HR_STAFF, 'name' => '人事担当者']));

        return $hr;
    }

    public function test_hr_staff_can_create_a_named_special_leave_type(): void
    {
        $hr = $this->actingAsHr();

        $response = $this->actingAs($hr)->postJson('/api/special-leave/types', [
            'name' => '誕生日休暇',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('name', '誕生日休暇');
        $response->assertJsonPath('is_active', true);
    }

    public function test_employee_cannot_create_a_special_leave_type(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee)->postJson('/api/special-leave/types', [
            'name' => '誕生日休暇',
        ])->assertForbidden();
    }

    public function test_hr_staff_can_deactivate_a_special_leave_type(): void
    {
        $hr = $this->actingAsHr();
        $type = SpecialLeaveType::query()->create(['name' => '誕生日休暇', 'is_active' => true]);

        $response = $this->actingAs($hr)->putJson("/api/special-leave/types/{$type->id}", [
            'name' => '誕生日休暇',
            'is_active' => false,
        ]);

        $response->assertOk();
        $response->assertJsonPath('is_active', false);
    }

    public function test_hr_staff_can_grant_special_leave_with_an_expiry_date(): void
    {
        $hr = $this->actingAsHr();
        $employee = User::factory()->create();
        $type = SpecialLeaveType::query()->create(['name' => '誕生日休暇', 'is_active' => true]);

        $response = $this->actingAs($hr)->postJson('/api/special-leave/grants', [
            'user_id' => $employee->id,
            'special_leave_type_id' => $type->id,
            'granted_on' => '2026-07-01',
            'expires_on' => '2026-12-31',
            'granted_days' => 3,
            'grant_reason' => '誕生月のため付与',
        ]);

        $response->assertCreated();
        $this->assertEquals(3.0, $response->json('remaining_days'));
        $response->assertJsonPath('expires_on', '2026-12-31');
    }

    public function test_hr_staff_can_grant_special_leave_that_never_expires(): void
    {
        $hr = $this->actingAsHr();
        $employee = User::factory()->create();
        $type = SpecialLeaveType::query()->create(['name' => 'リフレッシュ休暇', 'is_active' => true]);

        $response = $this->actingAs($hr)->postJson('/api/special-leave/grants', [
            'user_id' => $employee->id,
            'special_leave_type_id' => $type->id,
            'granted_on' => '2026-07-01',
            'granted_days' => 5,
        ]);

        $response->assertCreated();
        $this->assertNull($response->json('expires_on'));

        $mine = $this->actingAs($employee)->getJson('/api/special-leave/grants/mine');
        $mine->assertOk();
        $this->assertCount(1, $mine->json());
        $this->assertNull($mine->json()[0]['expires_on']);
    }

    public function test_employee_cannot_grant_special_leave(): void
    {
        $employee = User::factory()->create();
        $other = User::factory()->create();
        $type = SpecialLeaveType::query()->create(['name' => '誕生日休暇', 'is_active' => true]);

        $this->actingAs($employee)->postJson('/api/special-leave/grants', [
            'user_id' => $other->id,
            'special_leave_type_id' => $type->id,
            'granted_on' => '2026-07-01',
            'granted_days' => 3,
        ])->assertForbidden();
    }
}
