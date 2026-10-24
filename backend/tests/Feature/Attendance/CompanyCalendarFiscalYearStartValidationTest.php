<?php

namespace Tests\Feature\Attendance;

use App\Models\CompanyCalendar;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 会社カレンダーの年度開始の月日(有給の一斉付与日にも使われる)が実在することの検証
 * (docs/changesets/20261021-paid-leave-fiscal-mass-grant-and-auto-assessment/spec.md 論点3)。
 */
class CompanyCalendarFiscalYearStartValidationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $this->assignRole($admin, Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']));

        return $admin;
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function nonexistentMonthDays(): array
    {
        return [
            '4/31' => [4, 31],
            '6/31' => [6, 31],
            '9/31' => [9, 31],
            '11/31' => [11, 31],
            '2/30' => [2, 30],
            '2/29(閏年にしか無く、毎年の年度開始日に使えない)' => [2, 29],
        ];
    }

    #[DataProvider('nonexistentMonthDays')]
    public function test_store_rejects_nonexistent_month_day(int $month, int $day): void
    {
        $this->actingAs($this->admin())->postJson('/api/company-calendars', [
            'name' => '本社カレンダー',
            'fiscal_year_start_month' => $month,
            'fiscal_year_start_day' => $day,
        ])->assertUnprocessable()->assertJsonValidationErrors(['fiscal_year_start_day']);

        $this->assertSame(0, CompanyCalendar::query()->count());
    }

    #[DataProvider('nonexistentMonthDays')]
    public function test_update_rejects_nonexistent_month_day_and_keeps_the_calendar(int $month, int $day): void
    {
        $admin = $this->admin();
        $id = $this->actingAs($admin)->postJson('/api/company-calendars', ['name' => '本社カレンダー'])->assertCreated()->json('id');

        $this->actingAs($admin)->putJson("/api/company-calendars/{$id}", [
            'name' => '本社カレンダー',
            'fiscal_year_start_month' => $month,
            'fiscal_year_start_day' => $day,
        ])->assertUnprocessable()->assertJsonValidationErrors(['fiscal_year_start_day']);

        $calendar = CompanyCalendar::query()->findOrFail($id);
        $this->assertSame([4, 1], [$calendar->fiscal_year_start_month, $calendar->fiscal_year_start_day]);
    }

    public function test_update_checks_the_combination_with_the_stored_value_when_only_one_is_sent(): void
    {
        $admin = $this->admin();
        $id = $this->actingAs($admin)->postJson('/api/company-calendars', [
            'name' => '本社カレンダー', 'fiscal_year_start_month' => 5, 'fiscal_year_start_day' => 31,
        ])->assertCreated()->json('id');

        // 保存済みの日(31)と、送った月(6)の組み合わせ(6/31)は存在しない。
        $this->actingAs($admin)->putJson("/api/company-calendars/{$id}", [
            'name' => '本社カレンダー', 'fiscal_year_start_month' => 6,
        ])->assertUnprocessable()->assertJsonValidationErrors(['fiscal_year_start_day']);
    }

    public function test_existing_month_days_are_accepted(): void
    {
        $admin = $this->admin();

        foreach ([[4, 1], [10, 16], [2, 28], [12, 31], [1, 31]] as [$month, $day]) {
            $this->actingAs($admin)->postJson('/api/company-calendars', [
                'name' => "カレンダー{$month}-{$day}",
                'fiscal_year_start_month' => $month,
                'fiscal_year_start_day' => $day,
            ])->assertCreated();
        }
    }

    public function test_default_month_day_without_input_is_valid(): void
    {
        $this->actingAs($this->admin())->postJson('/api/company-calendars', ['name' => '本社カレンダー'])
            ->assertCreated()
            ->assertJsonPath('fiscal_year_start_month', 4)
            ->assertJsonPath('fiscal_year_start_day', 1);
    }
}
