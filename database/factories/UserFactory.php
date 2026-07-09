<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'entra_user_id' => (string) Str::uuid(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'department' => fake()->randomElement(['営業部', '総務部', '経理部', '開発部']),
            'job_title' => fake()->jobTitle(),
            'employment_status' => 'active',
            'last_login_at' => null,
        ];
    }
}
