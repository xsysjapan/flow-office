<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WorkStyle;
use Database\Seeders\DefaultWorkStyleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DefaultWorkStyleSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_the_standard_default_work_style_once_a_user_exists(): void
    {
        User::factory()->create();

        $this->seed(DefaultWorkStyleSeeder::class);

        $this->assertSame(1, WorkStyle::query()->where('is_default', true)->count());
        $this->assertSame('通常勤務', WorkStyle::query()->where('is_default', true)->first()->name);
    }

    public function test_it_does_nothing_when_no_user_exists_yet(): void
    {
        $this->seed(DefaultWorkStyleSeeder::class);

        $this->assertSame(0, WorkStyle::query()->count());
    }

    public function test_it_is_idempotent_when_a_default_already_exists(): void
    {
        User::factory()->create();

        $this->seed(DefaultWorkStyleSeeder::class);
        $this->seed(DefaultWorkStyleSeeder::class);

        $this->assertSame(1, WorkStyle::query()->where('is_default', true)->count());
    }
}
