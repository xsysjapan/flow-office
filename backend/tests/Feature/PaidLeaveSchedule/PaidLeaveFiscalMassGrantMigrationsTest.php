<?php

namespace Tests\Feature\PaidLeaveSchedule;

use App\Models\CompanyCalendar;
use App\Models\PaidLeaveGrantPolicy;
use App\Models\PaidLeaveGrantRule;
use App\Models\PaidLeaveProportionalGrantPolicy;
use App\Models\WorkStyle;
use Database\Seeders\PaidLeaveGrantPolicySeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 年度はじめ一斉付与の変更セット(docs/changesets/20261021-paid-leave-fiscal-mass-grant-and-auto-assessment/spec.md 段階1)の
 * migration(付与方式の既定値・`mass_grant_month`の削除・区分の追加・uniqueの付け替え・法定の写し・比例の移行・名前・
 * `paid_leave_fiscal_year_settings`)の結果を確かめる。
 */
class PaidLeaveFiscalMassGrantMigrationsTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_NAME = '全社共通(年度はじめ一斉付与)';

    private function migration(string $file): Migration
    {
        return require database_path('migrations/'.$file);
    }

    private function dataMigration(): Migration
    {
        return $this->migration('2026_10_21_000004_seed_statutory_steps_and_migrate_proportional_paid_leave_grant_rule_steps.php');
    }

    /**
     * @return array<string, array<int, float>> 区分 => (継続勤務月数 => 日数)
     */
    private function tablesOf(int $ruleId): array
    {
        $tables = [];
        foreach (DB::table('paid_leave_grant_rule_steps')->where('rule_id', $ruleId)->orderBy('continuous_service_months')->get() as $step) {
            $tables[$step->grant_category][(int) $step->continuous_service_months] = (float) $step->grant_days;
        }
        ksort($tables);

        return $tables;
    }

    /**
     * シーダ(`PaidLeaveGrantPolicySeeder`)の法定値と同じ表。
     *
     * @return array<string, array<int, float>>
     */
    private function seederTables(): array
    {
        (new PaidLeaveGrantPolicySeeder)->run();

        $tables = ['normal' => PaidLeaveGrantPolicy::query()->orderBy('continuous_service_months')->pluck('grant_days', 'continuous_service_months')->map(fn ($d) => (float) $d)->all()];
        foreach (PaidLeaveProportionalGrantPolicy::query()->orderBy('continuous_service_months')->get() as $row) {
            $tables['proportional_'.$row->weekly_scheduled_days_category][(int) $row->continuous_service_months] = (float) $row->grant_days;
        }
        ksort($tables);

        return $tables;
    }

    private function globalRule(): PaidLeaveGrantRule
    {
        return PaidLeaveGrantRule::query()->whereNull('work_style_id')->orderBy('id')->firstOrFail();
    }

    private function createWorkStyle(string $code, ?float $weekly, ?int $annual): WorkStyle
    {
        return WorkStyle::query()->create([
            'code' => $code,
            'name' => $code,
            'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FIXED,
            'workday_boundary_type' => WorkStyle::WORKDAY_BOUNDARY_MIDNIGHT,
            'prescribed_daily_minutes' => 480,
            'prescribed_weekly_minutes' => 1200,
            'weekly_scheduled_days' => $weekly,
            'annual_scheduled_days' => $annual,
            'legal_holiday_rule' => WorkStyle::LEGAL_HOLIDAY_RULE_WEEKLY,
        ]);
    }

    private function createRuleFor(WorkStyle $workStyle, array $stepsByMonths): PaidLeaveGrantRule
    {
        $rule = PaidLeaveGrantRule::query()->create([
            'name' => $workStyle->code.'のルール',
            'work_style_id' => $workStyle->id,
            'min_attendance_rate' => 80,
            'first_grant_after_months' => 6,
            'grant_cycle_months' => 12,
            'is_active' => true,
        ]);
        foreach ($stepsByMonths as $months => $days) {
            $rule->steps()->create(['continuous_service_months' => $months, 'grant_days' => $days]);
        }

        return $rule;
    }

    public function test_initial_global_rule_is_renamed_and_has_the_statutory_copy_from_the_seeder_values(): void
    {
        $rule = $this->globalRule();

        $this->assertSame(self::NEW_NAME, $rule->name);
        $this->assertSame(PaidLeaveGrantRule::CYCLE_TYPE_MASS_GRANT_MONTH, $rule->grant_cycle_type);
        $this->assertSame(
            [
                'normal' => [6 => 10.0, 18 => 11.0, 30 => 12.0, 42 => 14.0, 54 => 16.0, 66 => 18.0, 78 => 20.0],
                'proportional_1' => [6 => 1.0, 18 => 2.0, 30 => 2.0, 42 => 2.0, 54 => 3.0, 66 => 3.0, 78 => 3.0],
                'proportional_2' => [6 => 3.0, 18 => 4.0, 30 => 4.0, 42 => 5.0, 54 => 6.0, 66 => 6.0, 78 => 7.0],
                'proportional_3' => [6 => 5.0, 18 => 6.0, 30 => 6.0, 42 => 8.0, 54 => 9.0, 66 => 10.0, 78 => 11.0],
                'proportional_4' => [6 => 7.0, 18 => 8.0, 30 => 9.0, 42 => 10.0, 54 => 12.0, 66 => 13.0, 78 => 15.0],
            ],
            $this->tablesOf($rule->id),
        );
        // マスタが無い環境の写し(上の値)は、シーダの値と同じ。
        $this->assertSame($this->seederTables(), $this->tablesOf($rule->id));
    }

    public function test_statutory_copy_uses_the_current_active_policy_version(): void
    {
        // 法定マスタに新しい版(v2。通常の6か月=11日、週3日の6か月=6日)があれば、その版から写す。
        PaidLeaveGrantPolicy::query()->create(['version' => 'v1', 'continuous_service_months' => 6, 'grant_days' => 10, 'effective_from' => '2000-01-01', 'is_active' => true]);
        PaidLeaveGrantPolicy::query()->create(['version' => 'v2', 'continuous_service_months' => 6, 'grant_days' => 11, 'effective_from' => '2020-01-01', 'is_active' => true]);
        PaidLeaveGrantPolicy::query()->create(['version' => 'v2', 'continuous_service_months' => 18, 'grant_days' => 12, 'effective_from' => '2020-01-01', 'is_active' => true]);
        PaidLeaveProportionalGrantPolicy::query()->create(['version' => 'v2', 'weekly_scheduled_days_category' => '3', 'continuous_service_months' => 6, 'grant_days' => 6, 'effective_from' => '2020-01-01', 'is_active' => true]);

        $rule = $this->globalRule();
        $rule->steps()->delete();
        $rule->update(['name' => '全社共通(4月一斉付与)']);

        $this->dataMigration()->up();

        $tables = $this->tablesOf($rule->id);
        $this->assertSame([6 => 11.0, 18 => 12.0], $tables['normal']);
        $this->assertSame([6 => 6.0], $tables['proportional_3']);
        // 週4/2/1日の表はマスタに無いので、その区分の写しは作らない(要確認になる)。
        $this->assertSame(['normal', 'proportional_3'], array_keys($tables));
        $this->assertSame(self::NEW_NAME, $rule->fresh()->name);
    }

    public function test_global_rules_with_steps_keep_them_and_other_names_are_untouched(): void
    {
        $rule = $this->globalRule();
        $rule->steps()->delete();
        $rule->steps()->create(['continuous_service_months' => 6, 'grant_days' => 12]);
        $rule->update(['name' => '管理者が付けた名前']);

        $this->dataMigration()->up();

        $this->assertSame(['normal' => [6 => 12.0]], $this->tablesOf($rule->id));
        $this->assertSame('管理者が付けた名前', $rule->fresh()->name);
    }

    public function test_proportional_work_style_rules_move_their_steps_to_the_proportional_category(): void
    {
        $weekly3 = $this->createRuleFor($this->createWorkStyle('W3', 3, null), [6 => 5, 18 => 6]);
        $annual100 = $this->createRuleFor($this->createWorkStyle('A100', null, 100), [6 => 3]);
        $weekly2 = $this->createRuleFor($this->createWorkStyle('W2', 2.5, null), [6 => 3]);
        $undecidable = $this->createRuleFor($this->createWorkStyle('W0', 0, null), [6 => 1]);
        $normal = $this->createRuleFor($this->createWorkStyle('W5', 5, null), [6 => 10]);
        $shift = $this->createRuleFor(
            WorkStyle::query()->create([
                'code' => 'SHIFT', 'name' => 'SHIFT', 'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FIXED,
                'workday_boundary_type' => WorkStyle::WORKDAY_BOUNDARY_MIDNIGHT, 'prescribed_daily_minutes' => 480,
                'prescribed_weekly_minutes' => 1200, 'weekly_scheduled_days' => 3, 'is_shift_based' => true,
                'legal_holiday_rule' => WorkStyle::LEGAL_HOLIDAY_RULE_WEEKLY,
            ]),
            [6 => 4],
        );

        $this->dataMigration()->up();

        $this->assertSame(['proportional_3' => [6 => 5.0, 18 => 6.0]], $this->tablesOf($weekly3->id));
        $this->assertSame(['proportional_2' => [6 => 3.0]], $this->tablesOf($annual100->id));
        $this->assertSame(['proportional_2' => [6 => 3.0]], $this->tablesOf($weekly2->id));
        // 区分を決められない比例(週0日)と、通常・シフトは通常の表のまま。
        $this->assertSame(['normal' => [6 => 1.0]], $this->tablesOf($undecidable->id));
        $this->assertSame(['normal' => [6 => 10.0]], $this->tablesOf($normal->id));
        $this->assertSame(['normal' => [6 => 4.0]], $this->tablesOf($shift->id));
    }

    public function test_data_migration_can_run_twice_without_changing_the_result(): void
    {
        $weekly3 = $this->createRuleFor($this->createWorkStyle('W3', 3, null), [6 => 5]);

        $this->dataMigration()->up();
        $this->dataMigration()->up();

        $this->assertSame(['proportional_3' => [6 => 5.0]], $this->tablesOf($weekly3->id));
        $this->assertSame(35, DB::table('paid_leave_grant_rule_steps')->where('rule_id', $this->globalRule()->id)->count());
    }

    public function test_rule_default_grant_cycle_type_is_mass_grant_month_and_the_column_is_gone(): void
    {
        $this->assertFalse(Schema::hasColumn('paid_leave_grant_rules', 'mass_grant_month'));

        $id = DB::table('paid_leave_grant_rules')->insertGetId([
            'name' => '既定値の確認', 'min_attendance_rate' => 80, 'first_grant_after_months' => 6,
            'grant_cycle_months' => 12, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame('mass_grant_month', DB::table('paid_leave_grant_rules')->where('id', $id)->value('grant_cycle_type'));
    }

    public function test_dropping_mass_grant_month_reports_rules_with_a_different_month_than_the_default_calendar(): void
    {
        $migration = $this->migration('2026_10_21_000002_default_paid_leave_grant_rules_to_mass_grant_and_drop_mass_grant_month.php');
        $migration->down();
        $this->assertTrue(Schema::hasColumn('paid_leave_grant_rules', 'mass_grant_month'));

        CompanyCalendar::query()->create(['name' => '標準', 'week_starts_on' => 1, 'fiscal_year_start_month' => 4, 'fiscal_year_start_day' => 1, 'is_default' => true]);
        foreach ([['同じ月のルール', 4], ['違う月のルール', 7]] as [$name, $month]) {
            DB::table('paid_leave_grant_rules')->insert([
                'name' => $name, 'grant_cycle_type' => 'mass_grant_month', 'mass_grant_month' => $month,
                'min_attendance_rate' => 80, 'first_grant_after_months' => 6, 'grant_cycle_months' => 12,
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $messages = [];
        Log::shouldReceive('warning')->andReturnUsing(function (string $message) use (&$messages) {
            $messages[] = $message;
        });

        $migration->up();

        $this->assertFalse(Schema::hasColumn('paid_leave_grant_rules', 'mass_grant_month'));
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('違う月のルール', $messages[0]);
        $this->assertStringContainsString('7月', $messages[0]);
        $this->assertStringContainsString('4月', $messages[0]);
    }

    public function test_steps_are_unique_per_rule_category_and_months(): void
    {
        $this->assertTrue(Schema::hasColumn('paid_leave_grant_rule_steps', 'grant_category'));
        $rule = $this->createRuleFor($this->createWorkStyle('W3', 3, null), []);

        $rule->steps()->create(['grant_category' => 'normal', 'continuous_service_months' => 6, 'grant_days' => 10]);
        // 区分が違えば、同じ継続勤務月数を持てる。
        $rule->steps()->create(['grant_category' => 'proportional_3', 'continuous_service_months' => 6, 'grant_days' => 5]);
        // 区分を省略すると通常。
        $this->assertSame('normal', DB::table('paid_leave_grant_rule_steps')->where('rule_id', $rule->id)->where('continuous_service_months', 6)->orderBy('id')->value('grant_category'));

        $this->expectException(QueryException::class);
        $rule->steps()->create(['grant_category' => 'normal', 'continuous_service_months' => 6, 'grant_days' => 11]);
    }

    public function test_down_drops_the_proportional_steps_so_the_old_unique_can_be_restored(): void
    {
        $rule = $this->createRuleFor($this->createWorkStyle('W3D', 3, null), []);
        $rule->steps()->create(['grant_category' => 'normal', 'continuous_service_months' => 6, 'grant_days' => 10]);
        $rule->steps()->create(['grant_category' => 'proportional_3', 'continuous_service_months' => 6, 'grant_days' => 5]);

        $migration = $this->migration('2026_10_21_000003_add_grant_category_to_paid_leave_grant_rule_steps_table.php');
        $migration->down();

        $this->assertFalse(Schema::hasColumn('paid_leave_grant_rule_steps', 'grant_category'));
        $steps = DB::table('paid_leave_grant_rule_steps')->where('rule_id', $rule->id)->get();
        $this->assertCount(1, $steps);
        $this->assertSame(6, (int) $steps[0]->continuous_service_months);
        $this->assertSame(10.0, (float) $steps[0]->grant_days);

        $migration->up();

        $this->assertTrue(Schema::hasColumn('paid_leave_grant_rule_steps', 'grant_category'));
    }

    public function test_fiscal_year_settings_table_gets_the_initial_value_from_the_default_calendar(): void
    {
        $migration = $this->migration('2026_10_21_000005_create_paid_leave_fiscal_year_settings_table.php');

        // 既定のカレンダーが無ければ行を作らない(行が無いときは4月1日として扱う)。
        $migration->down();
        $migration->up();
        $this->assertSame(0, DB::table('paid_leave_fiscal_year_settings')->count());

        // 今の既定のカレンダーから初期値を入れる(既定でないカレンダーは使わない)。
        $migration->down();
        CompanyCalendar::query()->create(['name' => '既定でない', 'week_starts_on' => 1, 'fiscal_year_start_month' => 1, 'fiscal_year_start_day' => 1, 'is_default' => false]);
        $default = CompanyCalendar::query()->create(['name' => '既定', 'week_starts_on' => 1, 'fiscal_year_start_month' => 10, 'fiscal_year_start_day' => 16, 'is_default' => true]);
        $migration->up();

        $row = DB::table('paid_leave_fiscal_year_settings')->first();
        $this->assertSame([1, $default->id, 10, 16, null], [(int) $row->id, $row->company_calendar_id, (int) $row->fiscal_year_start_month, (int) $row->fiscal_year_start_day, $row->last_changed_event_id]);
    }
}
