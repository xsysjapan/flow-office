<?php

namespace Tests\Unit\PaidLeaveSchedule;

use App\Domain\PaidLeaveSchedule\Support\GrantCategoryClassifier;
use Tests\TestCase;

/**
 * `GrantCategoryClassifier`の単体テスト。DBアクセスを行わない純粋なロジックテストのため、
 * `WorkStyle`モデルの代わりに必要な属性だけを持つstdClassスタブで検証する
 * (spec.md 論点3-4、依頼書§34-35)。
 */
class GrantCategoryClassifierTest extends TestCase
{
    private function stub(array $attrs): object
    {
        return (object) array_merge([
            'weekly_scheduled_days' => null,
            'annual_scheduled_days' => null,
            'prescribed_weekly_minutes' => null,
            'is_shift_based' => false,
        ], $attrs);
    }

    public function test_weekly_scheduled_days_five_or_more_is_normal(): void
    {
        $classifier = new GrantCategoryClassifier();

        $result = $classifier->classify($this->stub(['weekly_scheduled_days' => 5]));

        $this->assertSame(GrantCategoryClassifier::CATEGORY_NORMAL, $result);
    }

    public function test_weekly_scheduled_days_four_is_not_normal_by_itself(): void
    {
        $classifier = new GrantCategoryClassifier();

        $result = $classifier->classify($this->stub(['weekly_scheduled_days' => 4, 'annual_scheduled_days' => 100]));

        $this->assertSame(GrantCategoryClassifier::CATEGORY_PROPORTIONAL, $result);
    }

    public function test_prescribed_weekly_minutes_1800_or_more_is_normal(): void
    {
        $classifier = new GrantCategoryClassifier();

        $result = $classifier->classify($this->stub(['prescribed_weekly_minutes' => 1800]));

        $this->assertSame(GrantCategoryClassifier::CATEGORY_NORMAL, $result);
    }

    public function test_prescribed_weekly_minutes_just_below_threshold_is_not_normal_by_itself(): void
    {
        $classifier = new GrantCategoryClassifier();

        $result = $classifier->classify($this->stub(['prescribed_weekly_minutes' => 1799, 'weekly_scheduled_days' => 3, 'annual_scheduled_days' => 150]));

        $this->assertSame(GrantCategoryClassifier::CATEGORY_PROPORTIONAL, $result);
    }

    public function test_annual_scheduled_days_217_or_more_is_normal(): void
    {
        $classifier = new GrantCategoryClassifier();

        $result = $classifier->classify($this->stub(['annual_scheduled_days' => 217]));

        $this->assertSame(GrantCategoryClassifier::CATEGORY_NORMAL, $result);
    }

    public function test_annual_scheduled_days_216_is_not_normal_by_itself(): void
    {
        $classifier = new GrantCategoryClassifier();

        $result = $classifier->classify($this->stub(['annual_scheduled_days' => 216, 'weekly_scheduled_days' => 3]));

        $this->assertSame(GrantCategoryClassifier::CATEGORY_PROPORTIONAL, $result);
    }

    public function test_is_shift_based_is_shift_when_not_normal(): void
    {
        $classifier = new GrantCategoryClassifier();

        $result = $classifier->classify($this->stub(['is_shift_based' => true, 'weekly_scheduled_days' => 3, 'annual_scheduled_days' => 100]));

        $this->assertSame(GrantCategoryClassifier::CATEGORY_SHIFT, $result);
    }

    public function test_shift_based_takes_precedence_over_missing_data(): void
    {
        $classifier = new GrantCategoryClassifier();

        $result = $classifier->classify($this->stub(['is_shift_based' => true]));

        $this->assertSame(GrantCategoryClassifier::CATEGORY_SHIFT, $result);
    }

    public function test_otherwise_is_proportional(): void
    {
        $classifier = new GrantCategoryClassifier();

        $result = $classifier->classify($this->stub(['weekly_scheduled_days' => 3, 'annual_scheduled_days' => 150]));

        $this->assertSame(GrantCategoryClassifier::CATEGORY_PROPORTIONAL, $result);
    }

    public function test_missing_required_attributes_needs_review(): void
    {
        $classifier = new GrantCategoryClassifier();

        $result = $classifier->classify($this->stub([]));

        $this->assertSame(GrantCategoryClassifier::CATEGORY_NEEDS_REVIEW, $result);
    }

    public function test_missing_annual_days_only_still_classifiable_from_weekly_days(): void
    {
        $classifier = new GrantCategoryClassifier();

        $result = $classifier->classify($this->stub(['weekly_scheduled_days' => 2, 'annual_scheduled_days' => null]));

        $this->assertSame(GrantCategoryClassifier::CATEGORY_PROPORTIONAL, $result);
    }
}
