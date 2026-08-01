<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/system-settings: フロントエンドの起動時ブートストラップ設定(デフォルト
 * タイムゾーン・デフォルト働き方・勤怠提出/締め期限日・有給/特別休暇の承認要否)を
 * まとめて返す軽量エンドポイント。SystemSettingResource(role:admin限定、M365設定・
 * 通知メール設定等の機微な項目を含む)とは別に、一般社員も参照できる必要がある。
 */
class PublicSystemSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_non_admin_authenticated_user_can_read_the_leave_approval_settings(): void
    {
        SystemSetting::current()->update([
            'paid_leave_requires_approval' => false,
            'special_leave_requires_approval' => true,
        ]);

        $employee = User::factory()->create();

        $response = $this->actingAs($employee)->getJson('/api/system-settings');

        $response->assertOk();
        $response->assertJson([
            'paid_leave_requires_approval' => false,
            'special_leave_requires_approval' => true,
        ]);
    }

    public function test_a_non_admin_authenticated_user_can_read_the_full_public_bootstrap_settings(): void
    {
        $workStyle = WorkStyle::query()->create([
            'code' => 'default-work-style', 'name' => '通常勤務', 'work_time_system' => 'fixed',
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_break_minutes' => 60, 'calendar_id' => null, 'is_shift_based' => false,
        ]);

        SystemSetting::current()->update([
            'default_timezone' => 'Asia/Tokyo',
            'default_work_style_id' => $workStyle->id,
            'attendance_submission_deadline_day' => 5,
            'attendance_month_close_deadline_day' => 10,
            'paid_leave_requires_approval' => false,
            'special_leave_requires_approval' => true,
        ]);

        $employee = User::factory()->create();

        $response = $this->actingAs($employee)->getJson('/api/system-settings');

        $response->assertOk();
        $response->assertExactJson([
            'default_timezone' => 'Asia/Tokyo',
            'default_work_style_id' => $workStyle->id,
            'default_work_style' => [
                'id' => $workStyle->id,
                'code' => 'default-work-style',
                'name' => '通常勤務',
            ],
            'attendance_submission_deadline_day' => 5,
            'attendance_month_close_deadline_day' => 10,
            'paid_leave_requires_approval' => false,
            'special_leave_requires_approval' => true,
        ]);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/system-settings')->assertUnauthorized();
    }
}
