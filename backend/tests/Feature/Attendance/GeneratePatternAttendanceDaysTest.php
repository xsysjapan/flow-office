<?php

namespace Tests\Feature\Attendance;

use App\Domain\Attendance\Commands\GeneratePatternAttendanceDays;
use App\Models\AttendanceDay;
use App\Models\AttendanceMonth;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 実績(attendance_days)の週次・月次一括入力。曜日ごとの実際の出退勤・休憩時刻を
 * 指定期間へ展開し(週次)、月次はさらに特定日だけの上書きを重ねて確定できる。
 * 日ごとに既存の単日Command(CreateAttendanceDay/EditAttendanceDay)を呼び出すため、
 * 締め済み等の理由で1日だけ拒否されても他の日の生成は継続される。
 */
class GeneratePatternAttendanceDaysTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 平日(月〜金)9:00〜18:00・休憩12:00〜13:00、土日は未設定(対象外)の週次パターン。
     */
    private function weekdayPattern(): array
    {
        $weekday = ['start_time' => '09:00', 'end_time' => '18:00', 'break_start_time' => '12:00', 'break_end_time' => '13:00'];

        return [1 => $weekday, 2 => $weekday, 3 => $weekday, 4 => $weekday, 5 => $weekday, 6 => null, 7 => null];
    }

    public function test_preview_pattern_does_not_persist_anything(): void
    {
        $employee = User::factory()->create();

        $response = $this->actingAs($employee)->postJson('/api/attendance/days/preview-pattern', [
            'from' => '2026-08-01',
            'to' => '2026-08-09',
            'utc_offset' => '+09:00',
            'weekly_pattern' => $this->weekdayPattern(),
        ]);

        $response->assertOk();
        $days = $response->json('days');
        // 2026-08-01は土曜日のため、週次パターンでは対象外(生成されない)。
        $this->assertSame('2026-08-03', $days[0]['date']);
        $this->assertSame('09:00', $days[0]['start_time']);
        $this->assertSame(0, AttendanceDay::query()->count());
    }

    public function test_weekly_pattern_creates_attendance_days_only_on_matching_weekdays(): void
    {
        $employee = User::factory()->create();

        // 2026-08-01は土曜日、08-03は月曜日。
        $response = $this->actingAs($employee)->postJson('/api/attendance/days/generate-pattern', [
            'user_id' => $employee->id,
            'from' => '2026-08-01',
            'to' => '2026-08-09',
            'utc_offset' => '+09:00',
            'weekly_pattern' => $this->weekdayPattern(),
            'reason' => '週次一括入力',
        ]);

        $response->assertOk();
        $this->assertSame(5, $response->json('created_count'));
        $this->assertSame(0, $response->json('rejected_count'));

        $this->assertSame(0, AttendanceDay::query()->whereDate('work_date', '2026-08-01')->count());

        $monday = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', '2026-08-03')->firstOrFail();
        $this->assertSame('09:00', $monday->actual_start_at->format('H:i'));
        $this->assertSame('18:00', $monday->actual_end_at->format('H:i'));
        $this->assertSame(480, $monday->calculation->work_minutes);
    }

    public function test_monthly_day_override_applies_only_to_that_day(): void
    {
        $employee = User::factory()->create();

        // 08-04(火)は本来平日出勤だが、この日だけ短時間勤務にする。
        $response = $this->actingAs($employee)->postJson('/api/attendance/days/generate-pattern', [
            'user_id' => $employee->id,
            'from' => '2026-08-01',
            'to' => '2026-08-09',
            'utc_offset' => '+09:00',
            'weekly_pattern' => $this->weekdayPattern(),
            'day_overrides' => [
                '2026-08-04' => ['start_time' => '10:00', 'end_time' => '15:00'],
            ],
            'reason' => '月次一括入力(日単位の個別設定つき)',
        ]);

        $response->assertOk();

        $overridden = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', '2026-08-04')->firstOrFail();
        $this->assertSame('10:00', $overridden->actual_start_at->format('H:i'));
        $this->assertSame('15:00', $overridden->actual_end_at->format('H:i'));
        $this->assertEmpty($overridden->breaks);

        $unaffected = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', '2026-08-03')->firstOrFail();
        $this->assertSame('09:00', $unaffected->actual_start_at->format('H:i'));
    }

    public function test_existing_days_are_skipped_by_default_and_updated_with_overwrite_existing(): void
    {
        $employee = User::factory()->create();

        $this->actingAs($employee)->postJson('/api/attendance/days/generate-pattern', [
            'user_id' => $employee->id,
            'from' => '2026-08-01',
            'to' => '2026-08-09',
            'utc_offset' => '+09:00',
            'weekly_pattern' => $this->weekdayPattern(),
            'reason' => '1回目の一括入力',
        ])->assertOk();

        $skipResponse = $this->actingAs($employee)->postJson('/api/attendance/days/generate-pattern', [
            'user_id' => $employee->id,
            'from' => '2026-08-01',
            'to' => '2026-08-09',
            'utc_offset' => '+09:00',
            'weekly_pattern' => $this->weekdayPattern(),
            'reason' => '2回目(既定はスキップされるはず)',
        ]);
        $skipResponse->assertOk();
        $this->assertSame(0, $skipResponse->json('created_count'));
        $this->assertSame(5, $skipResponse->json('skipped_count'));

        $overwriteResponse = $this->actingAs($employee)->postJson('/api/attendance/days/generate-pattern', [
            'user_id' => $employee->id,
            'from' => '2026-08-01',
            'to' => '2026-08-09',
            'utc_offset' => '+09:00',
            'weekly_pattern' => [1 => ['start_time' => '10:00', 'end_time' => '19:00', 'break_start_time' => '12:00', 'break_end_time' => '13:00'], 2 => null, 3 => null, 4 => null, 5 => null, 6 => null, 7 => null],
            'overwrite_mode' => GeneratePatternAttendanceDays::OVERWRITE_MODE_OVERWRITE_EXISTING,
            'reason' => '3回目(上書き)',
        ]);
        $overwriteResponse->assertOk();
        $this->assertSame(1, $overwriteResponse->json('updated_count'));

        $monday = AttendanceDay::query()->where('user_id', $employee->id)->whereDate('work_date', '2026-08-03')->firstOrFail();
        $this->assertSame('10:00', $monday->actual_start_at->format('H:i'));
    }

    public function test_a_locked_month_rejects_only_that_month_and_continues_the_batch(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();

        AttendanceMonth::query()->create([
            'user_id' => $employee->id, 'year_month' => '2026-08', 'status' => 'approved',
            'approver_user_id' => $approver->id,
        ]);

        $response = $this->actingAs($employee)->postJson('/api/attendance/days/generate-pattern', [
            'user_id' => $employee->id,
            'from' => '2026-08-01',
            'to' => '2026-09-06',
            'utc_offset' => '+09:00',
            'weekly_pattern' => $this->weekdayPattern(),
            'reason' => '承認済み月をまたぐ一括入力',
        ]);

        $response->assertOk();
        $this->assertGreaterThan(0, $response->json('rejected_count'));
        $this->assertGreaterThan(0, $response->json('created_count'));
        $this->assertSame(0, AttendanceDay::query()->whereBetween('work_date', ['2026-08-01', '2026-08-31'])->count());
        $this->assertGreaterThan(0, AttendanceDay::query()->whereBetween('work_date', ['2026-09-01', '2026-09-06'])->count());
    }

    public function test_generating_for_another_users_days_requires_admin_role(): void
    {
        $employee = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($other)->postJson('/api/attendance/days/generate-pattern', [
            'user_id' => $employee->id,
            'from' => '2026-08-01',
            'to' => '2026-08-09',
            'utc_offset' => '+09:00',
            'weekly_pattern' => $this->weekdayPattern(),
            'reason' => '他人の実績を一括入力しようとするテスト',
        ])->assertForbidden();

        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        $this->actingAs($admin)->postJson('/api/attendance/days/generate-pattern', [
            'user_id' => $employee->id,
            'from' => '2026-08-01',
            'to' => '2026-08-09',
            'utc_offset' => '+09:00',
            'weekly_pattern' => $this->weekdayPattern(),
            'reason' => '管理者による代理一括入力',
        ])->assertOk();
    }
}
