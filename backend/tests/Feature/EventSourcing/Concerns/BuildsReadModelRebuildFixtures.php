<?php

namespace Tests\Feature\EventSourcing\Concerns;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Rebuild\ReadModelRebuilder;
use App\Domain\UserManagement\Commands\AddMembership;
use App\Domain\UserManagement\Commands\CreateUser;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\UserManagementSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ReadModelの作り直しのテストの前提データ(すべてCommand経由で作る。イベントから作り直せる行だけにする)。
 */
trait BuildsReadModelRebuildFixtures
{
    /**
     * `DatabaseMigrations`の`runDatabaseMigrations()`の代わり(各クラスのoverrideから呼ぶ)。
     * 標準の後始末(`migrate:rollback`)は、SQLiteで`down()`が失敗する既存のマイグレーションがあるため使えない。
     * 代わりに、終了時に`db:wipe`でテーブルごと消し、`RefreshDatabaseState::$migrated`を戻して、後続の`RefreshDatabase`の
     * テストが`migrate:fresh`し直すようにする(ファイルDBでも、このテストの行を後続のテストに残さない)。
     */
    protected function runRebuildDatabaseMigrations(): void
    {
        $this->artisan('migrate:fresh');
        $this->app[\Illuminate\Contracts\Console\Kernel::class]->setArtisan(null);

        $this->beforeApplicationDestroyed(function (): void {
            $this->artisan('db:wipe');
            RefreshDatabaseState::$migrated = false;
        });
    }

    protected function seedAccessControl(): void
    {
        $this->seed([UserManagementSeeder::class, AccessControlSeeder::class]);
    }

    /** `CreateUser`(イベントあり)で利用者を作る。 */
    protected function createEventUser(string $name): User
    {
        // 作成者(`memberships.created_by`の外部キー)には、作る利用者自身を使う(最初の利用者に作成者が居ないため)。
        $id = (string) Str::uuid();

        return app(CommandBus::class)->dispatch(new CreateUser(
            $id,
            ['name' => $name, 'email' => Str::slug($name).'-'.Str::random(6).'@example.com', 'employment_status' => 'active', 'account_status' => 'active'],
            $id,
        ));
    }

    /** システム管理者のグループ(管理者ロールがglobalで割り当てられている)に所属する、イベントから作り直せる利用者。 */
    protected function createEventAdmin(string $name = 'Rebuild Admin'): User
    {
        $user = $this->createEventUser($name);
        $groupId = DB::table('groups')->where('code', 'SYSTEM_ADMINISTRATORS')->value('id');
        app(CommandBus::class)->dispatch(new AddMembership($user->id, $groupId, 'member', false, null));

        return $user;
    }

    protected function clearRebuildHeartbeat(): void
    {
        ReadModelRebuilder::clearHeartbeat();
    }
}
