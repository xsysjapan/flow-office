<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/leave-approval-settings: 有給・特別休暇の申請フォームが承認者指定を必須にすべきかを
 * 判断するための軽量エンドポイント。SystemSettingResource(role:admin限定)とは別に、
 * 一般社員も参照できる必要がある。
 */
class LeaveApprovalSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_non_admin_authenticated_user_can_read_the_leave_approval_settings(): void
    {
        SystemSetting::current()->update([
            'paid_leave_requires_approval' => false,
            'special_leave_requires_approval' => true,
        ]);

        $employee = User::factory()->create();

        $response = $this->actingAs($employee)->getJson('/api/leave-approval-settings');

        $response->assertOk();
        $response->assertExactJson([
            'paid_leave_requires_approval' => false,
            'special_leave_requires_approval' => true,
        ]);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/leave-approval-settings')->assertUnauthorized();
    }
}
