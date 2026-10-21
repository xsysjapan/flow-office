<?php

namespace Tests\Unit\PaidLeaveSchedule;

use App\Domain\PaidLeaveSchedule\Support\PaidLeaveGrantRuleResolver;
use App\Models\PaidLeaveGrantRule;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 付与ルールの選択の順(勤務形態のルール→全社ルール、同じ種類は`created_at`→`id`の古い方。
 * docs/changesets/20261021-paid-leave-fiscal-mass-grant-and-auto-assessment/spec.md 論点4)。
 */
class PaidLeaveGrantRuleResolverTest extends TestCase
{
    use RefreshDatabase;

    private function createWorkStyle(): WorkStyle
    {
        return WorkStyle::query()->create([
            'code' => 'NORMAL',
            'name' => '通常勤務',
            'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FIXED,
            'workday_boundary_type' => WorkStyle::WORKDAY_BOUNDARY_MIDNIGHT,
            'prescribed_daily_minutes' => 480,
            'prescribed_weekly_minutes' => 2400,
            'weekly_scheduled_days' => 5,
            'legal_holiday_rule' => WorkStyle::LEGAL_HOLIDAY_RULE_WEEKLY,
        ]);
    }

    /**
     * created_atはEloquentの自動設定を避けるため、作成後にDBで直接設定する(並び順の検証用)。
     */
    private function createRule(string $name, string $createdAt, ?WorkStyle $workStyle = null, bool $isActive = true): PaidLeaveGrantRule
    {
        $rule = PaidLeaveGrantRule::query()->create([
            'name' => $name,
            'work_style_id' => $workStyle?->id,
            'min_attendance_rate' => 80,
            'first_grant_after_months' => 6,
            'grant_cycle_months' => 12,
            'grant_cycle_type' => PaidLeaveGrantRule::CYCLE_TYPE_ANNIVERSARY,
            'is_active' => $isActive,
        ]);
        DB::table('paid_leave_grant_rules')->where('id', $rule->id)->update(['created_at' => $createdAt]);

        return $rule;
    }

    /**
     * マイグレーションの全社共通ルールを無効化する(このテストの対象の件数を明確にするため)。
     */
    private function deactivateSeededGlobalRules(): void
    {
        PaidLeaveGrantRule::query()->whereNull('work_style_id')->update(['is_active' => false]);
    }

    private function resolver(): PaidLeaveGrantRuleResolver
    {
        return new PaidLeaveGrantRuleResolver;
    }

    public function test_it_picks_the_oldest_global_rule_when_there_are_several(): void
    {
        $this->deactivateSeededGlobalRules();

        // 新しい方を先に作っても、created_atが古い方が選ばれる。
        $newer = $this->createRule('新しい全社ルール', '2026-02-01 00:00:00');
        $older = $this->createRule('古い全社ルール', '2026-01-01 00:00:00');

        $this->assertSame($older->id, $this->resolver()->resolveFor(null)?->id);
        $this->assertNotSame($newer->id, $this->resolver()->resolveFor(null)?->id);
    }

    public function test_it_breaks_created_at_ties_by_id(): void
    {
        $this->deactivateSeededGlobalRules();

        $first = $this->createRule('1件目', '2026-01-01 00:00:00');
        $this->createRule('2件目', '2026-01-01 00:00:00');

        $this->assertSame($first->id, $this->resolver()->resolveFor(null)?->id);
    }

    public function test_it_prefers_work_style_rule_over_global_rule(): void
    {
        $this->deactivateSeededGlobalRules();
        $workStyle = $this->createWorkStyle();
        $global = $this->createRule('全社ルール', '2026-01-01 00:00:00');
        // 勤務形態ルールは全社ルールより新しい作成日時でも優先される。
        $specific = $this->createRule('勤務形態ルール', '2026-03-01 00:00:00', $workStyle);

        $this->assertSame($specific->id, $this->resolver()->resolveFor($workStyle)?->id);
        $this->assertSame($global->id, $this->resolver()->resolveFor(null)?->id);
    }

    public function test_it_falls_back_to_the_global_rule_when_the_work_style_has_no_active_rule(): void
    {
        $this->deactivateSeededGlobalRules();
        $workStyle = $this->createWorkStyle();
        $global = $this->createRule('全社ルール', '2026-01-01 00:00:00');
        $this->createRule('無効な勤務形態ルール', '2026-01-01 00:00:00', $workStyle, isActive: false);

        $this->assertSame($global->id, $this->resolver()->resolveFor($workStyle)?->id);
    }

    public function test_it_returns_null_when_no_active_rule_exists_and_loads_steps(): void
    {
        $this->deactivateSeededGlobalRules();
        $this->assertNull($this->resolver()->resolveFor(null));

        $rule = $this->createRule('全社ルール', '2026-01-01 00:00:00');
        $rule->steps()->create(['grant_category' => 'normal', 'continuous_service_months' => 6, 'grant_days' => 10]);

        $resolved = $this->resolver()->resolveFor(null);

        $this->assertTrue($resolved->relationLoaded('steps'));
        $this->assertCount(1, $resolved->steps);
    }
}
