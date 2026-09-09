<?php

namespace Tests\Unit\PaidLeaveSchedule;

use App\Domain\PaidLeaveSchedule\Support\GrantCategory;
use App\Domain\PaidLeaveSchedule\Support\GrantCategoryClassifier;
use App\Models\CompanyCalendar;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 通常/比例/シフト区分判定。spec.md「仕様確定事項」参照。
 * 週5日以上/週30時間以上/年217日以上 → 通常、is_shift_based → シフト、
 * 必要な列が未入力 → NeedsReview。
 */
class GrantCategoryClassifierTest extends TestCase
{
    use RefreshDatabase;

    private function makeWorkStyle(array $overrides = []): WorkStyle
    {
        $calendar = CompanyCalendar::query()->create(['name' => '2026年度', 'week_starts_on' => 1]);
        $calendar->years()->create(['fiscal_year' => 2026, 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31', 'status' => 'published']);

        return WorkStyle::query()->create(array_merge([
            'code' => 'standard-'.uniqid(), 'name' => '勤務形態', 'work_time_system' => 'fixed',
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 1600,
            'default_start_time' => '09:00', 'default_end_time' => '18:00', 'default_break_minutes' => 60,
            'company_calendar_id' => $calendar->id, 'is_shift_based' => false,
        ], $overrides));
    }

    public function test_weekly_scheduled_days_5_or_more_is_regular(): void
    {
        $workStyle = $this->makeWorkStyle(['weekly_scheduled_days' => 5]);
        $this->assertSame(GrantCategory::REGULAR, (new GrantCategoryClassifier)->classify($workStyle));
    }

    public function test_weekly_scheduled_days_4_is_not_regular_by_itself(): void
    {
        $workStyle = $this->makeWorkStyle(['weekly_scheduled_days' => 4]);
        $this->assertSame(GrantCategory::PROPORTIONAL, (new GrantCategoryClassifier)->classify($workStyle));
    }

    public function test_weekly_minutes_1800_or_more_is_regular(): void
    {
        $workStyle = $this->makeWorkStyle(['prescribed_weekly_minutes' => 1800, 'weekly_scheduled_days' => 4]);
        $this->assertSame(GrantCategory::REGULAR, (new GrantCategoryClassifier)->classify($workStyle));
    }

    public function test_annual_scheduled_days_217_or_more_is_regular(): void
    {
        $workStyle = $this->makeWorkStyle(['annual_scheduled_days' => 217, 'weekly_scheduled_days' => 3]);
        $this->assertSame(GrantCategory::REGULAR, (new GrantCategoryClassifier)->classify($workStyle));
    }

    public function test_annual_scheduled_days_216_is_not_regular_by_itself(): void
    {
        $workStyle = $this->makeWorkStyle(['annual_scheduled_days' => 216, 'weekly_scheduled_days' => 3]);
        $this->assertSame(GrantCategory::PROPORTIONAL, (new GrantCategoryClassifier)->classify($workStyle));
    }

    public function test_is_shift_based_is_shift_regardless_of_other_fields(): void
    {
        $workStyle = $this->makeWorkStyle(['is_shift_based' => true, 'weekly_scheduled_days' => 5]);
        $this->assertSame(GrantCategory::SHIFT, (new GrantCategoryClassifier)->classify($workStyle));
    }

    public function test_missing_all_required_fields_is_needs_review(): void
    {
        $workStyle = $this->makeWorkStyle();
        $this->assertSame(GrantCategory::NEEDS_REVIEW, (new GrantCategoryClassifier)->classify($workStyle));
    }

    public function test_null_work_style_is_needs_review(): void
    {
        $this->assertSame(GrantCategory::NEEDS_REVIEW, (new GrantCategoryClassifier)->classify(null));
    }
}
