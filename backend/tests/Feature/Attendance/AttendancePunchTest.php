<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceDay;
use App\Models\AttendancePunch;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WorkCalendar;
use App\Models\WorkStyle;
use App\Support\LocalDateTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * UC-A012: 打刻ログ。矛盾があっても記録は成功し、矛盾なく組み立てられた場合のみ
 * 日次勤怠(attendance_days)に反映されることを確認する。
 *
 * APIの日時は必ずオフセット付きISO8601で送る。内部では入力された通りの壁時計時刻を
 * タイムゾーン変換せずに保存し、そのオフセット(分)を別途 utc_offset_minutes に記録する
 * (docs/03-architecture.md 3.4)。社員本人の既定タイムゾーン(users.timezone)には
 * 変換しない。
 */
class AttendancePunchTest extends TestCase
{
    use RefreshDatabase;

    public function test_consistent_punches_are_synced_to_the_attendance_day(): void
    {
        $employee = User::factory()->create();
        $workDate = '2026-07-09';

        $this->recordPunch($employee, $workDate, 'clock_in', '2026-07-09T09:00:00+09:00')->assertSuccessful();
        $this->recordPunch($employee, $workDate, 'break_start', '2026-07-09T12:00:00+09:00')->assertSuccessful();
        $this->recordPunch($employee, $workDate, 'break_end', '2026-07-09T13:00:00+09:00')->assertSuccessful();
        $this->recordPunch($employee, $workDate, 'clock_out', '2026-07-09T18:00:00+09:00')->assertSuccessful();

        $day = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', $workDate)->first();

        $this->assertNotNull($day);
        $this->assertSame('punch', $day->source);
        $this->assertSame('clocked_out', $day->status);
        $this->assertSame(1, $day->breaks()->count());
        $this->assertNotNull($day->calculation);
        $this->assertSame(480, $day->calculation->work_minutes);
    }

    public function test_completed_punches_are_rounded_to_the_work_styles_rounding_unit_on_sync(): void
    {
        $employee = User::factory()->create();
        $workDate = '2026-07-09';

        $calendar = WorkCalendar::query()->create([
            'name' => '2026年度', 'fiscal_year' => 2026,
            'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
            'week_starts_on' => 1, 'status' => 'published',
        ]);
        $workStyle = WorkStyle::query()->create([
            'code' => 'standard', 'name' => '通常勤務', 'work_time_system' => 'fixed',
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_break_minutes' => 60, 'rounding_unit_minutes' => 30,
            'calendar_id' => $calendar->id, 'is_shift_based' => false,
        ]);
        SystemSetting::current()->update(['default_work_style_id' => $workStyle->id]);

        // 30分単位への四捨五入: 08:53→09:00, 12:02→12:00, 12:58→13:00, 18:12→18:00。
        $this->recordPunch($employee, $workDate, 'clock_in', "{$workDate}T08:53:00+09:00")->assertSuccessful();
        $this->recordPunch($employee, $workDate, 'break_start', "{$workDate}T12:02:00+09:00")->assertSuccessful();
        $this->recordPunch($employee, $workDate, 'break_end', "{$workDate}T12:58:00+09:00")->assertSuccessful();
        $this->recordPunch($employee, $workDate, 'clock_out', "{$workDate}T18:12:00+09:00")->assertSuccessful();

        $day = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', $workDate)->first();

        $this->assertNotNull($day);
        $this->assertSame('2026-07-09T09:00:00+09:00', LocalDateTime::formatWithOffsetMinutes($day->actual_start_at, $day->utc_offset_minutes));
        $this->assertSame('2026-07-09T18:00:00+09:00', LocalDateTime::formatWithOffsetMinutes($day->actual_end_at, $day->utc_offset_minutes));

        $break = $day->breaks()->first();
        $this->assertSame('2026-07-09T12:00:00+09:00', LocalDateTime::formatWithOffsetMinutes($break->break_start_at, $day->utc_offset_minutes));
        $this->assertSame('2026-07-09T13:00:00+09:00', LocalDateTime::formatWithOffsetMinutes($break->break_end_at, $day->utc_offset_minutes));

        // 丸め後の実働時間: 09:00〜18:00から休憩60分を引いた480分。
        $this->assertSame(480, $day->calculation->work_minutes);
    }

    public function test_nearest_rounding_rounds_the_half_unit_up(): void
    {
        $employee = User::factory()->create();
        $workDate = '2026-07-09';
        $this->createWorkStyleWithRounding(30, WorkStyle::ROUNDING_MODE_NEAREST);

        // 四捨五入: ちょうど半分の8:45は繰り上げて9:00、8:44は繰り下げて8:30。
        $this->recordPunch($employee, $workDate, 'clock_in', "{$workDate}T08:45:00+09:00")->assertSuccessful();
        $this->recordPunch($employee, $workDate, 'clock_out', "{$workDate}T18:00:00+09:00")->assertSuccessful();

        $day = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', $workDate)->first();
        $this->assertSame('2026-07-09T09:00:00+09:00', LocalDateTime::formatWithOffsetMinutes($day->actual_start_at, $day->utc_offset_minutes));

        $employee2 = User::factory()->create();
        $this->recordPunch($employee2, $workDate, 'clock_in', "{$workDate}T08:44:00+09:00")->assertSuccessful();
        $this->recordPunch($employee2, $workDate, 'clock_out', "{$workDate}T18:00:00+09:00")->assertSuccessful();

        $day2 = AttendanceDay::query()->where('user_id', $employee2->id)->whereDate('work_date', $workDate)->first();
        $this->assertSame('2026-07-09T08:30:00+09:00', LocalDateTime::formatWithOffsetMinutes($day2->actual_start_at, $day2->utc_offset_minutes));
    }

    public function test_shorten_rounding_shortens_the_paid_work_time(): void
    {
        $employee = User::factory()->create();
        $workDate = '2026-07-09';
        $this->createWorkStyleWithRounding(30, WorkStyle::ROUNDING_MODE_SHORTEN);

        // 切り捨て(会社有利): 出勤は繰り上げ(8:31〜9:00→9:00)、退勤は繰り下げ(18:00〜18:29→18:00)、
        // 休憩開始は繰り下げ(12:00〜12:29→12:00)、休憩終了は繰り上げ(12:31〜13:00→13:00)。
        $this->recordPunch($employee, $workDate, 'clock_in', "{$workDate}T08:31:00+09:00")->assertSuccessful();
        $this->recordPunch($employee, $workDate, 'break_start', "{$workDate}T12:29:00+09:00")->assertSuccessful();
        $this->recordPunch($employee, $workDate, 'break_end', "{$workDate}T12:31:00+09:00")->assertSuccessful();
        $this->recordPunch($employee, $workDate, 'clock_out', "{$workDate}T18:29:00+09:00")->assertSuccessful();

        $day = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', $workDate)->first();
        $break = $day->breaks()->first();

        $this->assertSame('2026-07-09T09:00:00+09:00', LocalDateTime::formatWithOffsetMinutes($day->actual_start_at, $day->utc_offset_minutes));
        $this->assertSame('2026-07-09T18:00:00+09:00', LocalDateTime::formatWithOffsetMinutes($day->actual_end_at, $day->utc_offset_minutes));
        $this->assertSame('2026-07-09T12:00:00+09:00', LocalDateTime::formatWithOffsetMinutes($break->break_start_at, $day->utc_offset_minutes));
        $this->assertSame('2026-07-09T13:00:00+09:00', LocalDateTime::formatWithOffsetMinutes($break->break_end_at, $day->utc_offset_minutes));
    }

    public function test_lengthen_rounding_lengthens_the_paid_work_time(): void
    {
        $employee = User::factory()->create();
        $workDate = '2026-07-09';
        $this->createWorkStyleWithRounding(30, WorkStyle::ROUNDING_MODE_LENGTHEN);

        // 切り上げ(社員有利): 出勤は繰り下げ(9:00〜9:29→9:00)、退勤は繰り上げ(17:31〜18:00→18:00)、
        // 休憩開始は繰り上げ(11:31〜12:00→12:00)、休憩終了は繰り下げ(13:00〜13:29→13:00)。
        $this->recordPunch($employee, $workDate, 'clock_in', "{$workDate}T09:29:00+09:00")->assertSuccessful();
        $this->recordPunch($employee, $workDate, 'break_start', "{$workDate}T11:31:00+09:00")->assertSuccessful();
        $this->recordPunch($employee, $workDate, 'break_end', "{$workDate}T13:29:00+09:00")->assertSuccessful();
        $this->recordPunch($employee, $workDate, 'clock_out', "{$workDate}T17:31:00+09:00")->assertSuccessful();

        $day = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', $workDate)->first();
        $break = $day->breaks()->first();

        $this->assertSame('2026-07-09T09:00:00+09:00', LocalDateTime::formatWithOffsetMinutes($day->actual_start_at, $day->utc_offset_minutes));
        $this->assertSame('2026-07-09T18:00:00+09:00', LocalDateTime::formatWithOffsetMinutes($day->actual_end_at, $day->utc_offset_minutes));
        $this->assertSame('2026-07-09T12:00:00+09:00', LocalDateTime::formatWithOffsetMinutes($break->break_start_at, $day->utc_offset_minutes));
        $this->assertSame('2026-07-09T13:00:00+09:00', LocalDateTime::formatWithOffsetMinutes($break->break_end_at, $day->utc_offset_minutes));
    }

    public function test_clock_in_only_is_rounded_while_still_working(): void
    {
        $employee = User::factory()->create();
        $workDate = '2026-07-09';
        $this->createWorkStyleWithRounding(30, WorkStyle::ROUNDING_MODE_NEAREST);

        // 退勤がまだ無く「勤務中」のままでも、四捨五入(8:45→9:00)は反映される。
        $this->recordPunch($employee, $workDate, 'clock_in', "{$workDate}T08:45:00+09:00")->assertSuccessful();

        $day = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', $workDate)->first();

        $this->assertNotNull($day);
        $this->assertSame('working', $day->status);
        $this->assertSame('2026-07-09T09:00:00+09:00', LocalDateTime::formatWithOffsetMinutes($day->actual_start_at, $day->utc_offset_minutes));
    }

    public function test_break_start_only_keeps_the_rounded_clock_in_while_on_break(): void
    {
        $employee = User::factory()->create();
        $workDate = '2026-07-09';
        $this->createWorkStyleWithRounding(30, WorkStyle::ROUNDING_MODE_NEAREST);

        // 出勤(8:45→9:00に丸め)の後、退勤せずに休憩開始だけした場合も、
        // 丸め済みの出勤時刻が休憩中のまま保たれる。
        $this->recordPunch($employee, $workDate, 'clock_in', "{$workDate}T08:45:00+09:00")->assertSuccessful();
        $this->recordPunch($employee, $workDate, 'break_start', "{$workDate}T12:00:00+09:00")->assertSuccessful();

        $day = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', $workDate)->first();

        $this->assertNotNull($day);
        $this->assertSame('on_break', $day->status);
        $this->assertSame('2026-07-09T09:00:00+09:00', LocalDateTime::formatWithOffsetMinutes($day->actual_start_at, $day->utc_offset_minutes));
    }

    public function test_a_punch_offset_different_from_the_owners_timezone_is_preserved_on_the_day(): void
    {
        $employee = User::factory()->create(); // timezone: Asia/Tokyo (既定値)
        $workDate = '2026-07-09';

        // 出張先の現地時刻(UTC+00:00)で打刻された場合、本人の既定タイムゾーン(+09:00)には
        // 変換せず、打刻された通りのオフセットを勤務日に記録する(docs/03-architecture.md 3.4)。
        $this->recordPunch($employee, $workDate, 'clock_in', '2026-07-09T00:00:00+00:00')->assertSuccessful();
        $this->recordPunch($employee, $workDate, 'clock_out', '2026-07-09T09:00:00+00:00')->assertSuccessful();

        $day = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', $workDate)->first();

        $this->assertSame(0, $day->utc_offset_minutes);
        $this->assertSame('2026-07-09T00:00:00+00:00', LocalDateTime::formatWithOffsetMinutes($day->actual_start_at, $day->utc_offset_minutes));
        $this->assertSame('2026-07-09T09:00:00+00:00', LocalDateTime::formatWithOffsetMinutes($day->actual_end_at, $day->utc_offset_minutes));

        $response = $this->actingAs($employee)->getJson("/api/attendance/days/{$day->id}");
        $response->assertJsonPath('actual_start_at', '2026-07-09T00:00:00+00:00');
        $response->assertJsonPath('actual_end_at', '2026-07-09T09:00:00+00:00');
        $response->assertJsonPath('utc_offset_minutes', 0);
    }

    public function test_punches_with_mismatched_offsets_are_treated_as_inconsistent(): void
    {
        $employee = User::factory()->create();
        $workDate = '2026-07-09';

        // 出勤と退勤で異なるオフセットが混在する場合、壁時計時刻どうしの前後比較に意味がなく
        // なるため矛盾ありとし、実績時刻・日次計算には反映しない。
        $this->recordPunch($employee, $workDate, 'clock_in', '2026-07-09T09:00:00+09:00')->assertSuccessful();
        $this->recordPunch($employee, $workDate, 'clock_out', '2026-07-09T09:00:00-05:00')->assertSuccessful();

        $day = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', $workDate)->first();

        // ただし出勤打刻の時点で「勤務中」の状態自体は画面操作と同様に反映済みのため、
        // 矛盾ありと判定された退勤打刻ではこの状態を変えない(実績・計算は反映されない)。
        $this->assertNotNull($day);
        $this->assertSame('working', $day->status);
        $this->assertNull($day->actual_end_at);
        $this->assertNull($day->calculation);
    }

    public function test_overnight_shift_punches_belong_to_the_explicit_work_date(): void
    {
        $employee = User::factory()->create();
        $workDate = '2026-07-09';

        // 21:00に出勤し、翌日6:00に退勤する夜勤。どちらもwork_date=2026-07-09に属させる。
        $this->recordPunch($employee, $workDate, 'clock_in', '2026-07-09T21:00:00+09:00')->assertSuccessful();
        $this->recordPunch($employee, $workDate, 'clock_out', '2026-07-10T06:00:00+09:00')->assertSuccessful();

        $day = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', $workDate)->first();

        $this->assertNotNull($day);
        $this->assertSame('2026-07-09T21:00:00+09:00', LocalDateTime::formatWithOffsetMinutes($day->actual_start_at, $day->utc_offset_minutes));
        $this->assertSame('2026-07-10T06:00:00+09:00', LocalDateTime::formatWithOffsetMinutes($day->actual_end_at, $day->utc_offset_minutes));
        $this->assertSame(540, $day->calculation->work_minutes);
    }

    public function test_inconsistent_punches_are_recorded_but_do_not_touch_the_attendance_day_calculation(): void
    {
        $employee = User::factory()->create();
        $workDate = '2026-07-09';

        // clock_inが2件(打刻漏れ・重複を想定)なので矛盾あり。
        $this->recordPunch($employee, $workDate, 'clock_in', '2026-07-09T09:00:00+09:00')->assertSuccessful();
        $this->recordPunch($employee, $workDate, 'clock_in', '2026-07-09T09:05:00+09:00')->assertSuccessful();

        $this->assertSame(2, AttendancePunch::query()->where('user_id', $employee->id)->count());

        $day = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', $workDate)->first();

        // 最初の出勤打刻の時点で「勤務中」の状態は反映されるが、矛盾があるため
        // 実績時刻・日次計算には反映しない(矛盾の解消はUC-A005の日次編集で行う)。
        $this->assertNotNull($day);
        $this->assertSame('working', $day->status);
        $this->assertNull($day->actual_end_at);
        $this->assertNull($day->calculation);
    }

    public function test_punches_do_not_overwrite_a_day_already_clocked_out_via_the_web_flow(): void
    {
        $employee = User::factory()->create();
        $today = Carbon::today($employee->timezone)->toDateString();

        Carbon::setTestNow(Carbon::parse("{$today} 09:00:00", $employee->timezone));
        $this->actingAs($employee)->postJson('/api/attendance/clock-in')->assertSuccessful();
        Carbon::setTestNow(Carbon::parse("{$today} 18:00:00", $employee->timezone));
        $this->actingAs($employee)->postJson('/api/attendance/clock-out')->assertSuccessful();
        Carbon::setTestNow();

        $webDay = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', $today)->first();
        $webActualStart = $webDay->actual_start_at->toIso8601String();

        // 同じ日に対して(矛盾のない)打刻が別途届いても、WEB画面の出退勤操作で既に
        // 退勤済みとして確定した日は上書きしない(WEB・端末のどちらの打刻経由でも
        // source='punch'に統一されているが、退勤済みの日を保護する規則は変わらない)。
        $this->recordPunch($employee, $today, 'clock_in', "{$today}T21:00:00+09:00")->assertSuccessful();
        $this->recordPunch($employee, $today, 'clock_out', "{$today}T23:00:00+09:00")->assertSuccessful();

        $webDay->refresh();
        $this->assertSame('punch', $webDay->source);
        $this->assertSame('clocked_out', $webDay->status);
        $this->assertSame($webActualStart, $webDay->actual_start_at->toIso8601String());
    }

    public function test_live_clock_actions_are_listed_as_web_punches_for_the_attendance_day(): void
    {
        $employee = User::factory()->create();
        $workDate = Carbon::today($employee->timezone)->toDateString();

        $this->actingAs($employee)->postJson('/api/attendance/clock-in')->assertSuccessful();
        $this->actingAs($employee)->postJson('/api/attendance/break/start')->assertSuccessful();
        $this->actingAs($employee)->postJson('/api/attendance/break/end')->assertSuccessful();
        $this->actingAs($employee)->postJson('/api/attendance/clock-out')->assertSuccessful();

        $response = $this->actingAs($employee)->getJson("/api/attendance-punches?from={$workDate}&to={$workDate}");

        $response->assertSuccessful();
        $this->assertSame(['clock_in', 'break_start', 'break_end', 'clock_out'], array_column($response->json(), 'punch_type'));
        $this->assertSame(['web', 'web', 'web', 'web'], array_column($response->json(), 'source'));
    }

    public function test_punches_do_not_overwrite_a_locked_day_even_if_punch_sourced(): void
    {
        $employee = User::factory()->create();
        $workDate = '2026-07-09';

        $this->recordPunch($employee, $workDate, 'clock_in', '2026-07-09T09:00:00+09:00')->assertSuccessful();
        $this->recordPunch($employee, $workDate, 'clock_out', '2026-07-09T18:00:00+09:00')->assertSuccessful();

        $day = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', $workDate)->first();
        $this->assertSame('punch', $day->source);
        $day->locked_at = Carbon::now();
        $day->save();

        // 元の打刻を消し、新たに(それ単体では矛盾のない)打刻を記録しても、
        // 締め後にロック済みの日は上書きしない。
        AttendancePunch::query()->where('user_id', $employee->id)->delete();
        $this->recordPunch($employee, $workDate, 'clock_in', '2026-07-09T08:00:00+09:00')->assertSuccessful();
        $this->recordPunch($employee, $workDate, 'clock_out', '2026-07-09T20:00:00+09:00')->assertSuccessful();

        $day->refresh();
        $this->assertSame('2026-07-09T09:00:00+09:00', LocalDateTime::formatWithOffsetMinutes($day->actual_start_at, $day->utc_offset_minutes));
        $this->assertSame('2026-07-09T18:00:00+09:00', LocalDateTime::formatWithOffsetMinutes($day->actual_end_at, $day->utc_offset_minutes));
    }

    public function test_recording_a_punch_for_another_user_requires_admin_role(): void
    {
        $employee = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($employee)->postJson('/api/attendance-punches', [
            'user_id' => $other->id,
            'work_date' => '2026-07-09',
            'punch_type' => 'clock_in',
            'punched_at' => '2026-07-09T09:00:00+09:00',
            'source' => 'ic_card',
        ])->assertForbidden();

        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        $this->actingAs($admin)->postJson('/api/attendance-punches', [
            'user_id' => $other->id,
            'work_date' => '2026-07-09',
            'punch_type' => 'clock_in',
            'punched_at' => '2026-07-09T09:00:00+09:00',
            'source' => 'ic_card',
        ])->assertSuccessful();

        $this->assertSame(1, AttendancePunch::query()->where('user_id', $other->id)->count());
    }

    public function test_a_punch_without_an_offset_is_rejected(): void
    {
        $employee = User::factory()->create();

        $this->recordPunch($employee, '2026-07-09', 'clock_in', '2026-07-09T09:00:00')
            ->assertStatus(422)
            ->assertJsonValidationErrors('punched_at');
    }

    public function test_index_lists_punches_for_the_given_date_range(): void
    {
        $employee = User::factory()->create();

        $this->recordPunch($employee, '2026-07-09', 'clock_in', '2026-07-09T09:00:00+09:00')->assertSuccessful();
        $this->recordPunch($employee, '2026-07-10', 'clock_in', '2026-07-10T09:00:00+09:00')->assertSuccessful();

        $response = $this->actingAs($employee)->getJson('/api/attendance-punches?from=2026-07-09&to=2026-07-09');

        $response->assertSuccessful();
        $this->assertCount(1, $response->json());
        $this->assertSame('2026-07-09', $response->json()[0]['work_date']);
    }

    private function recordPunch(User $user, string $workDate, string $punchType, string $punchedAt)
    {
        return $this->actingAs($user)->postJson('/api/attendance-punches', [
            'work_date' => $workDate,
            'punch_type' => $punchType,
            'punched_at' => $punchedAt,
            'source' => 'web',
        ]);
    }

    private function createWorkStyleWithRounding(int $roundingUnitMinutes, string $roundingMode): WorkStyle
    {
        $calendar = WorkCalendar::query()->create([
            'name' => '2026年度', 'fiscal_year' => 2026,
            'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
            'week_starts_on' => 1, 'status' => 'published',
        ]);
        $workStyle = WorkStyle::query()->create([
            'code' => 'standard-'.$roundingMode, 'name' => '通常勤務', 'work_time_system' => 'fixed',
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_break_minutes' => 60, 'rounding_unit_minutes' => $roundingUnitMinutes,
            'rounding_mode' => $roundingMode,
            'calendar_id' => $calendar->id, 'is_shift_based' => false,
        ]);
        SystemSetting::current()->update(['default_work_style_id' => $workStyle->id]);

        return $workStyle;
    }
}
