<?php

namespace Database\Seeders;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\UserManagement\Commands\AddMembership;
use App\Domain\UserManagement\Commands\CreateUser;
use App\Domain\UserManagement\Commands\LinkSsoAccount;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

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

        // admin@example.comはCreateUser(イベントを記録する)で作る。ReadModelを作り直しても消えないようにするため。
        // 既に同じメールの利用者がいれば何もしない。作成者には作る利用者自身を使う。
        $admin = User::query()->where('email', 'admin@example.com')->first();
        if ($admin === null) {
            $adminId = Uuid::uuid5(Uuid::NAMESPACE_URL, 'seed-user:admin@example.com')->toString();
            $admin = app(CommandBus::class)->dispatch(new CreateUser($adminId, [
                'name' => 'Test Admin',
                'email' => 'admin@example.com',
                'department' => '開発部',
                'job_title' => 'システム管理者',
                'employment_status' => 'active',
                'account_status' => 'active',
            ], $adminId));
        }

        // mock-oidcは`entra_user_id`のある利用者だけを出すため、mock有効時は管理者を`mock-entra-admin`と連携する。
        if (config('services.azure.mock_enabled') && $admin->entra_user_id === null) {
            app(CommandBus::class)->dispatch(new LinkSsoAccount($admin->id, 'mock-entra-admin'));
        }

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
