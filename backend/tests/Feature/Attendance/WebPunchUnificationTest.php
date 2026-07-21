<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceDay;
use App\Models\AttendancePunch;
use App\Models\AuthenticationKey;
use App\Models\AuthenticationKeyType;
use App\Models\Device;
use App\Models\DeviceOwnerType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * WEB画面の出退勤操作(UC-A001〜A004)は、共有端末・個人端末(UC-A012/UC-A020)と共通の
 * `RecordAttendancePunch`コマンド・`AttendanceDayPunchSyncer`を経由する
 * (docs/03-architecture.md 3.5「操作経路と業務ロジックを分離する」)。
 */
class WebPunchUnificationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_web_clock_actions_are_recorded_as_ordinary_attendance_punches(): void
    {
        $employee = User::factory()->create();
        $today = Carbon::today($employee->timezone);

        Carbon::setTestNow($today->copy()->setTime(9, 0));
        $this->actingAs($employee)->postJson('/api/attendance/clock-in')->assertSuccessful();
        Carbon::setTestNow($today->copy()->setTime(18, 0));
        $this->actingAs($employee)->postJson('/api/attendance/clock-out')->assertSuccessful();

        // 端末打刻と全く同じ`attendance_punches`テーブルに記録される(source='web'のみが違う)。
        $punches = AttendancePunch::query()->where('user_id', $employee->id)->orderBy('punched_at')->get();
        $this->assertSame(['clock_in', 'clock_out'], $punches->pluck('punch_type')->all());
        $this->assertSame(['web', 'web'], $punches->pluck('source')->all());
        $this->assertNull($punches->first()->device_id);
    }

    public function test_a_clock_in_from_a_personal_device_can_be_completed_by_the_web_clock_out_button(): void
    {
        $employee = User::factory()->create();
        $today = Carbon::today($employee->timezone);
        $device = Device::factory()->create([
            'owner_type' => DeviceOwnerType::PERSONAL,
            'owner_user_id' => $employee->id,
        ]);

        Carbon::setTestNow($today->copy()->setTime(9, 0));
        Sanctum::actingAs($device, ['punch:self']);
        $this->postJson('/api/device-punches', [
            'work_date' => $today->toDateString(),
            'punch_type' => 'clock_in',
            'punched_at' => $today->copy()->setTime(9, 0)->toIso8601String(),
        ])->assertSuccessful();

        // 出勤は個人端末から、退勤はWEB画面のボタンから。両者は同じ`RecordAttendancePunch`
        // /`AttendanceDayPunchSyncer`を通るため、1日分として矛盾なく組み立てられる。
        Carbon::setTestNow($today->copy()->setTime(18, 0));
        $this->actingAs($employee)->postJson('/api/attendance/clock-out')->assertSuccessful()
            ->assertJsonPath('status', 'clocked_out');

        $day = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', $today->toDateString())->firstOrFail();
        $this->assertSame('clocked_out', $day->status);
        $this->assertSame(540, $day->calculation->work_minutes);
    }

    public function test_clocking_in_is_rejected_when_the_day_was_already_finalized_by_manual_edit(): void
    {
        $employee = User::factory()->create();
        $today = Carbon::today($employee->timezone)->toDateString();

        // UC-A016: 出勤日を新規作成する(打刻を伴わない、日次編集による確定)。
        $this->actingAs($employee)->postJson('/api/attendance/days', [
            'user_id' => $employee->id,
            'work_date' => $today,
            'reason' => '出張のため打刻なしで先に作成',
        ])->assertCreated();

        // 日次編集で確定済みの日は、打刻(WEB画面のボタンも含む)では変更できない。
        $response = $this->actingAs($employee)->postJson('/api/attendance/clock-in');
        $response->assertStatus(422);

        $day = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', $today)->firstOrFail();
        $this->assertSame('not_started', $day->status);
        $this->assertSame('manual', $day->source);
    }

    public function test_shared_device_punch_and_web_punch_appear_together_in_the_same_punch_log(): void
    {
        $employee = User::factory()->create();
        $today = Carbon::today($employee->timezone);

        $this->actingAs($employee)->postJson('/api/users/me/authentication-keys', [
            'key_type' => AuthenticationKeyType::NFC_UID,
            'display_name' => 'カード',
            'raw_key_value' => 'NFC-UNIFICATION-001',
        ])->assertCreated();
        $key = AuthenticationKey::query()->where('user_id', $employee->id)->firstOrFail();

        Carbon::setTestNow($today->copy()->setTime(9, 0));
        $this->actingAs($employee)->postJson('/api/attendance/clock-in')->assertSuccessful();

        $device = Device::factory()->create(['owner_type' => DeviceOwnerType::ORGANIZATION_SHARED]);
        Sanctum::actingAs($device, ['recorder:punch']);
        Carbon::setTestNow($today->copy()->setTime(18, 0));
        $this->postJson('/api/device-punches', [
            'work_date' => $today->toDateString(),
            'punch_type' => 'clock_out',
            'punched_at' => $today->copy()->setTime(18, 0)->toIso8601String(),
            'authentication_key_value' => 'NFC-UNIFICATION-001',
        ])->assertSuccessful();

        $punches = AttendancePunch::query()->where('user_id', $employee->id)->orderBy('punched_at')->get();
        $this->assertSame(['web', 'device:'.$device->device_type], $punches->pluck('source')->all());

        $day = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', $today->toDateString())->firstOrFail();
        $this->assertSame('clocked_out', $day->status);
    }
}
