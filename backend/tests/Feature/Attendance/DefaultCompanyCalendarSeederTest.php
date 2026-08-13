<?php

namespace Tests\Feature\Attendance;

use App\Models\CompanyCalendar;
use App\Models\User;
use Database\Seeders\DefaultCompanyCalendarSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DefaultCompanyCalendarSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_the_standard_default_company_calendar_once_a_user_exists(): void
    {
        User::factory()->create();

        $this->seed(DefaultCompanyCalendarSeeder::class);

        $this->assertSame(1, CompanyCalendar::query()->where('is_default', true)->count());
        $this->assertSame('標準カレンダー', CompanyCalendar::query()->where('is_default', true)->first()->name);
    }

    public function test_it_does_nothing_when_no_user_exists_yet(): void
    {
        $this->seed(DefaultCompanyCalendarSeeder::class);

        $this->assertSame(0, CompanyCalendar::query()->count());
    }

    public function test_it_is_idempotent_when_a_default_already_exists(): void
    {
        User::factory()->create();

        $this->seed(DefaultCompanyCalendarSeeder::class);
        $this->seed(DefaultCompanyCalendarSeeder::class);

        $this->assertSame(1, CompanyCalendar::query()->where('is_default', true)->count());
    }
}
