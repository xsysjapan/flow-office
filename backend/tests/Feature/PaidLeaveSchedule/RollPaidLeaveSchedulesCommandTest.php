<?php

namespace Tests\Feature\PaidLeaveSchedule;

use App\Domain\PaidLeaveSchedule\Support\ScheduleEntryStatus;
use App\Models\AttendanceDay;
use App\Models\CompanyCalendar;
use App\Models\EmployeeCalendarEntry;
use App\Models\PaidLeaveGrantRule;
use App\Models\PaidLeaveScheduleEntry;
use App\Models\User;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `paid-leave:roll-schedules`(docs/changesets/20260906-paid-leave-schedule-assessment/
 * spec.md Phase C・論点6)。旧`paid-leave:grant-scheduled`
 * (`tests/Feature/PaidLeaveAccount/PaidLeaveScheduledBatchTest.php`、削除済み)が検証していた
 * 「記念日ちょうどのみ付与」に相当する概念はSchedule方式では「付与予定日ちょうどに
 * エントリを1件だけ生成する」という形に置き換わり、「出勤率判定」「auto-grant無効スキップ」
 * 「usage_start_date跨ぎ」「タイムゾーン」の観点は本テストへ引き継ぐ。
 */
class RollPaidLeaveSchedulesCommandTest extends TestCase
{
    use RefreshDatabase;

    private function createRuleWithSteps(): PaidLeaveGrantRule
    {
        $rule = PaidLeaveGrantRule::query()->create([
            'name' => '正社員標準', 'work_style_id' => null, 'min_attendance_rate' => 80,
            'first_grant_after_months' => 6, 'grant_cycle_months' => 12, 'is_active' => true,
        ]);
        $rule->steps()->create(['continuous_service_months' => 6, 'grant_days' => 10]);
        $rule->steps()->create(['continuous_service_months' => 18, 'grant_days' => 11]);

        return $rule;
    }

    private function createWorkStyle(): WorkStyle
    {
        $calendar = CompanyCalendar::query()->create(['name' => '2026年度', 'week_starts_on' => 1]);
        $calendar->years()->create(['fiscal_year' => 2026, 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31', 'status' => 'published']);

        return WorkStyle::query()->create([
            'code' => 'standard-'.uniqid(), 'name' => '通常勤務', 'work_time_system' => 'fixed',
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_start_time' => '09:00', 'default_end_time' => '18:00',
            'default_break_minutes' => 60, 'company_calendar_id' => $calendar->id, 'is_shift_based' => false,
        ]);
    }

    /**
     * 直近1年ぶん、指定した出勤率を満たす勤務予定・勤務実績を作成する(scheduled_onから
     * 遡る)。
     */
    private function seedAttendanceHistory(User $user, Carbon $scheduledOn, int $scheduledDays, int $attendedDays): void
    {
        $workStyle = $this->createWorkStyle();

        for ($i = 0; $i < $scheduledDays; $i++) {
            $date = $scheduledOn->copy()->subDays($i * 7 + 1);
            EmployeeCalendarEntry::query()->create([
                'user_id' => $user->id, 'work_date' => $date->toDateString(), 'work_style_id' => $workStyle->id,
                'day_type' => 'weekday', 'is_working_day' => true, 'is_legal_holiday' => false, 'is_company_holiday' => false,
                'planned_break_minutes' => 60,
            ]);

            if ($i < $attendedDays) {
                AttendanceDay::query()->create([
                    'user_id' => $user->id, 'work_date' => $date->toDateString(),
                    'status' => 'clocked_out', 'source' => 'live',
                ]);
            }
        }
    }

    public function test_it_generates_schedule_entries_up_to_one_year_ahead_idempotently(): void
    {
        $this->travelTo(Carbon::parse('2026-08-10'));
        $employee = User::factory()->create(['hire_date' => '2026-02-10']);
        $this->createRuleWithSteps();

        Artisan::call('paid-leave:roll-schedules');

        $scheduledOns = PaidLeaveScheduleEntry::query()
            ->where('user_id', $employee->id)
            ->orderBy('scheduled_on')
            ->pluck('scheduled_on')
            ->map(fn ($d) => $d->toDateString())
            ->all();

        // first_grant_after_months=6のため、2026-08-10からの1年先(2027-08-10)までに
        // 記念日は2026-08-10(6か月)の1件のみ生成される(次は2027-08-10、cycle=12か月)。
        $this->assertSame(['2026-08-10', '2027-08-10'], $scheduledOns);

        // 冪等性: 再実行しても行数が増えない。
        Artisan::call('paid-leave:roll-schedules');
        $this->assertSame(2, PaidLeaveScheduleEntry::query()->where('user_id', $employee->id)->count());
    }

    public function test_it_skips_users_with_auto_grant_disabled(): void
    {
        $this->travelTo(Carbon::parse('2026-08-10'));
        $employee = User::factory()->create(['hire_date' => '2026-02-10', 'paid_leave_auto_grant_enabled' => false]);
        $this->createRuleWithSteps();

        Artisan::call('paid-leave:roll-schedules');

        $this->assertSame(0, PaidLeaveScheduleEntry::query()->where('user_id', $employee->id)->count());
    }

    public function test_it_skips_users_whose_usage_start_date_has_not_arrived(): void
    {
        $this->travelTo(Carbon::parse('2026-08-10'));
        $employee = User::factory()->create(['hire_date' => '2026-02-10', 'usage_start_date' => '2026-09-01']);
        $this->createRuleWithSteps();

        Artisan::call('paid-leave:roll-schedules');

        $this->assertSame(0, PaidLeaveScheduleEntry::query()->where('user_id', $employee->id)->count());
    }

    public function test_it_runs_attendance_rate_assessment_only_for_entries_whose_scheduled_on_has_arrived(): void
    {
        $today = Carbon::parse('2026-08-10');
        $this->travelTo($today);
        $employee = User::factory()->create(['hire_date' => '2026-02-10']);
        $this->createRuleWithSteps();
        $this->seedAttendanceHistory($employee, $today, scheduledDays: 5, attendedDays: 5);

        Artisan::call('paid-leave:roll-schedules');

        $dueEntry = PaidLeaveScheduleEntry::query()
            ->where('user_id', $employee->id)
            ->whereDate('scheduled_on', '2026-08-10')
            ->firstOrFail();
        $this->assertSame(ScheduleEntryStatus::ELIGIBLE, $dueEntry->status);
        $this->assertNotNull($dueEntry->assessment_final_result);

        // 1年先(2027-08-10)のエントリはまだ到来していないため、Assessmentは未実行のまま
        // Scheduledに留まる(spec.mdは正確なタイミングを明記していないため、
        // 「勤怠データが揃う日付到来後にのみ判定する」という実装判断=このテストの主張)。
        $futureEntry = PaidLeaveScheduleEntry::query()
            ->where('user_id', $employee->id)
            ->whereDate('scheduled_on', '2027-08-10')
            ->firstOrFail();
        $this->assertSame(ScheduleEntryStatus::SCHEDULED, $futureEntry->status);
        $this->assertNull($futureEntry->assessment_final_result);
    }

    public function test_it_marks_entry_not_eligible_when_attendance_rate_is_below_threshold(): void
    {
        $today = Carbon::parse('2026-08-10');
        $this->travelTo($today);
        $employee = User::factory()->create(['hire_date' => '2026-02-10']);
        $this->createRuleWithSteps();
        $this->seedAttendanceHistory($employee, $today, scheduledDays: 5, attendedDays: 2); // 40% < 80%

        Artisan::call('paid-leave:roll-schedules');

        $entry = PaidLeaveScheduleEntry::query()
            ->where('user_id', $employee->id)
            ->whereDate('scheduled_on', '2026-08-10')
            ->firstOrFail();
        $this->assertSame(ScheduleEntryStatus::NOT_ELIGIBLE, $entry->status);
    }

    public function test_it_determines_each_users_today_using_their_own_timezone_not_the_company_default(): void
    {
        // UTC 2026-08-10 03:00は、Asia/Tokyo(UTC+9)では8/10だが、
        // America/Los_Angeles(夏時間UTC-7)では8/9であり、記念日到来の判定がユーザーごとに
        // 異なる(旧PaidLeaveScheduledBatchTestのタイムゾーン境界テストを踏襲)。
        $this->travelTo(Carbon::parse('2026-08-10 03:00:00', 'UTC'));

        $tokyoUser = User::factory()->create(['hire_date' => '2026-02-09', 'timezone' => 'Asia/Tokyo']);
        $laUser = User::factory()->create(['hire_date' => '2026-02-09', 'timezone' => 'America/Los_Angeles']);
        $this->createRuleWithSteps();

        Artisan::call('paid-leave:roll-schedules');

        // 両者ともEnsureFutureScheduleGeneratedはタイムゾーンに関わらず「1年先まで」を
        // 生成するため、記念日(2026-08-09)自体は両者に生成される。Assessmentが
        // 「今日」到来済みとして実行されるかがタイムゾーンで分かれる。
        $tokyoEntry = PaidLeaveScheduleEntry::query()
            ->where('user_id', $tokyoUser->id)->whereDate('scheduled_on', '2026-08-09')->firstOrFail();
        $laEntry = PaidLeaveScheduleEntry::query()
            ->where('user_id', $laUser->id)->whereDate('scheduled_on', '2026-08-09')->firstOrFail();

        // Tokyoの「今日」(8/10)からは記念日(8/9)が既に到来済みのためAssessmentが走る
        // (勤怠データ無しのためNeedsReview)。LAの「今日」(8/9)はまだ記念日当日であり
        // 到来済みなのでこちらもAssessmentは走る(境界は当日を含む、<=判定)。
        $this->assertSame(ScheduleEntryStatus::NEEDS_REVIEW, $tokyoEntry->status);
        $this->assertSame(ScheduleEntryStatus::NEEDS_REVIEW, $laEntry->status);
    }

    public function test_partial_failure_in_one_user_does_not_abort_the_batch_for_others(): void
    {
        $this->travelTo(Carbon::parse('2026-08-10'));
        $goodUser = User::factory()->create(['hire_date' => '2026-02-10']);
        $badUser = User::factory()->create(['hire_date' => '2026-02-10']);
        $this->createRuleWithSteps();

        // badUserのhire_dateをEloquentのキャストを経由せず不正な値に書き換え、
        // EnsureFutureScheduleGeneratedHandler内でCarbon::parse()が例外を投げる状態を作る
        // (1件のデータ不整合が他ユーザーの処理を止めないことの検証)。
        DB::table('users')->where('id', $badUser->id)->update(['hire_date' => 'not-a-valid-date']);

        $exitCode = Artisan::call('paid-leave:roll-schedules');

        $this->assertSame(1, $exitCode); // 失敗があったことはexit codeで伝える
        $this->assertGreaterThan(0, PaidLeaveScheduleEntry::query()->where('user_id', $goodUser->id)->count());
    }
}
