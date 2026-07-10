<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            SystemSettingSeeder::class,
            RoleSeeder::class,
            RequestTypeSeeder::class,
        ]);

        $admin = User::query()->firstOrCreate(
            ['email' => 'admin@example.com'],
            User::factory()->make([
                'name' => 'Test Admin',
                'email' => 'admin@example.com',
            ])->getAttributes(),
        );

        $adminRole = Role::query()->where('code', Role::ADMIN)->firstOrFail();
        $admin->roles()->syncWithoutDetaching([$adminRole->id]);
    }
}
