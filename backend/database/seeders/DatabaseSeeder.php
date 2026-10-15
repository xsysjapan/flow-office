<?php

namespace Database\Seeders;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\UserManagement\Commands\AddMembership;
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
            PaidLeaveGrantPolicySeeder::class,
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

        $administratorsGroupId = DB::table('groups')->where('code', 'SYSTEM_ADMINISTRATORS')->value('id');
        if (! DB::table('memberships')->where('user_id', $admin->id)->where('group_id', $administratorsGroupId)->exists()) {
            app(CommandBus::class)->dispatch(new AddMembership($admin->id, $administratorsGroupId, 'member', false, null));
        }
    }
}
