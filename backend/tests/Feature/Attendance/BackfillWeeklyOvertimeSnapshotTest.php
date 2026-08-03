<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceDay;
use App\Models\AttendanceDayStatus;
use App\Models\AttendanceMonth;
use App\Models\EmployeeShiftAssignment;
use App\Models\User;
use App\Models\WorkCalendar;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

/**
 * 週40時間超残業の月合計(weekly_statutory_excess_overtime_minutes)を確定値化する対応より前に
 * 提出済みだったattendance_month.submittedイベントに対する、event_properties.snapshotへの
 * 事後補完(BackfillWeeklyOvertimeSnapshotCommand)。
 */
class BackfillWeeklyOvertimeSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private function makeCalendar(): WorkCalendar
    {
        return WorkCalendar::query()->create([
            'name' => '2026年度', 'fiscal_year' => 2026,
            'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
            'week_starts_on' => 1, 'status' => 'published',
        ]);
    }

    private function makeWorkStyle(WorkCalendar $calendar): WorkStyle
    {
        return WorkStyle::query()->create([
            'code' => 'fixed-'.uniqid(), 'name' => '通常勤務', 'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FIXED,
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_break_minutes' => 60, 'calendar_id' => $calendar->id, 'is_shift_based' => false,
        ]);
    }

    private function recordDay(User $user, WorkStyle $workStyle, string $workDate, string $actualStart, string $actualEnd): void
    {
        $shift = EmployeeShiftAssignment::query()->create([
            'user_id' => $user->id, 'work_date' => $workDate, 'work_style_id' => $workStyle->id,
            'day_type' => 'weekday', 'is_working_day' => true, 'is_legal_holiday' => false, 'is_company_holiday' => false,
            'planned_break_minutes' => 60,
        ]);

        $day = AttendanceDay::query()->create([
            'user_id' => $user->id, 'work_date' => $workDate, 'shift_assignment_id' => $shift->id,
            'status' => AttendanceDayStatus::NOT_STARTED, 'source' => 'manual', 'utc_offset_minutes' => 540,
        ]);

        $this->actingAs($user)->putJson("/api/attendance/days/{$day->id}", [
            'actual_start_at' => "{$workDate}T{$actualStart}:00+09:00",
            'actual_end_at' => "{$workDate}T{$actualEnd}:00+09:00",
            'breaks' => [['start' => "{$workDate}T12:00:00+09:00", 'end' => "{$workDate}T13:00:00+09:00"]],
            'reason' => 'テストデータ投入',
        ])->assertOk();
    }

    /**
     * 導入前に提出済みだったイベント・Projectionを模して、両方からキーを取り除く
     * (実際に導入前に提出されていた場合、event_properties.snapshotとProjection先の
     * attendance_months.snapshot_jsonのどちらにもキーが存在しなかったはずのため)。
     */
    private function removeKeyFromLatestSubmittedEventAndProjection(): void
    {
        $event = EloquentStoredEvent::query()->where('event_class', 'attendance_month.submitted')->orderByDesc('id')->firstOrFail();
        $properties = $event->event_properties;
        unset($properties['snapshot']['weekly_statutory_excess_overtime_minutes']);
        $event->event_properties = $properties;
        $event->save();

        $month = AttendanceMonth::query()->where('id', $event->aggregate_uuid)->firstOrFail();
        $snapshot = $month->snapshot_json;
        unset($snapshot['weekly_statutory_excess_overtime_minutes']);
        $month->snapshot_json = $snapshot;
        $month->save();
    }

    public function test_backfills_the_missing_key_and_replays_it_into_the_projection(): void
    {
        $calendar = $this->makeCalendar();
        $workStyle = $this->makeWorkStyle($calendar);
        $user = User::factory()->create();

        // 06-01(月)〜06-06(土)を7時間労働(週42時間、週40時間超が120分)にする。
        foreach (['2026-06-01', '2026-06-02', '2026-06-03', '2026-06-04', '2026-06-05', '2026-06-06'] as $date) {
            $this->recordDay($user, $workStyle, $date, '09:00', '17:00');
        }

        $approver = User::factory()->create();
        $this->actingAs($user)->postJson('/api/attendance/months/2026-06/submit', [
            'approver_user_id' => $approver->id,
        ])->assertSuccessful();

        $this->removeKeyFromLatestSubmittedEventAndProjection();
        $month = AttendanceMonth::query()->where('user_id', $user->id)->where('year_month', '2026-06')->firstOrFail();
        $this->assertArrayNotHasKey('weekly_statutory_excess_overtime_minutes', $month->snapshot_json);

        $this->artisan('attendance:backfill-weekly-overtime-snapshot')->assertSuccessful();

        $event = EloquentStoredEvent::query()->where('event_class', 'attendance_month.submitted')->orderByDesc('id')->firstOrFail();
        $this->assertSame(120, $event->event_properties['snapshot']['weekly_statutory_excess_overtime_minutes']);

        $this->assertSame(120, $month->fresh()->snapshot_json['weekly_statutory_excess_overtime_minutes']);
    }

    public function test_dry_run_does_not_modify_the_event_or_the_projection(): void
    {
        $calendar = $this->makeCalendar();
        $workStyle = $this->makeWorkStyle($calendar);
        $user = User::factory()->create();

        foreach (['2026-06-01', '2026-06-02', '2026-06-03', '2026-06-04', '2026-06-05', '2026-06-06'] as $date) {
            $this->recordDay($user, $workStyle, $date, '09:00', '17:00');
        }

        $approver = User::factory()->create();
        $this->actingAs($user)->postJson('/api/attendance/months/2026-06/submit', [
            'approver_user_id' => $approver->id,
        ])->assertSuccessful();

        $this->removeKeyFromLatestSubmittedEventAndProjection();
        $month = AttendanceMonth::query()->where('user_id', $user->id)->where('year_month', '2026-06')->firstOrFail();

        $this->artisan('attendance:backfill-weekly-overtime-snapshot', ['--dry-run' => true])->assertSuccessful();

        $event = EloquentStoredEvent::query()->where('event_class', 'attendance_month.submitted')->orderByDesc('id')->firstOrFail();
        $this->assertArrayNotHasKey('weekly_statutory_excess_overtime_minutes', $event->event_properties['snapshot']);
        $this->assertArrayNotHasKey('weekly_statutory_excess_overtime_minutes', $month->fresh()->snapshot_json);
    }

    public function test_is_idempotent_when_run_a_second_time(): void
    {
        $calendar = $this->makeCalendar();
        $workStyle = $this->makeWorkStyle($calendar);
        $user = User::factory()->create();

        foreach (['2026-06-01', '2026-06-02', '2026-06-03', '2026-06-04', '2026-06-05', '2026-06-06'] as $date) {
            $this->recordDay($user, $workStyle, $date, '09:00', '17:00');
        }

        $approver = User::factory()->create();
        $this->actingAs($user)->postJson('/api/attendance/months/2026-06/submit', [
            'approver_user_id' => $approver->id,
        ])->assertSuccessful();

        // このテストでは提出直後から既にキーが含まれているため、1回目で対象0件のはず。
        $this->artisan('attendance:backfill-weekly-overtime-snapshot')->assertSuccessful();

        $event = EloquentStoredEvent::query()->where('event_class', 'attendance_month.submitted')->orderByDesc('id')->firstOrFail();
        $this->assertSame(120, $event->event_properties['snapshot']['weekly_statutory_excess_overtime_minutes']);
    }
}
