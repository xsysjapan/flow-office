<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

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
            EmploymentCategorySeeder::class,
            ExpenseCategorySeeder::class,
            ExpenseEntryPresetSeeder::class,
        ]);

        $admin = User::query()->firstOrCreate(
            ['email' => 'admin@example.com'],
            User::factory()->make([
                'name' => 'Test Admin',
                'email' => 'admin@example.com',
            ])->getAttributes(),
        );

        $this->call([
            UserManagementSeeder::class,
            AccessControlSeeder::class,
            DefaultWorkStyleSeeder::class,
            DefaultCompanyCalendarSeeder::class,
        ]);

        DB::table('memberships')->updateOrInsert(
            [
                'user_id' => $admin->id,
                'group_id' => DB::table('groups')->where('code', 'SYSTEM_ADMINISTRATORS')->value('id'),
            ],
            ['membership_kind' => 'member', 'updated_at' => now(), 'created_at' => now()],
        );
    }
}
