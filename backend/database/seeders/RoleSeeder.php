<?php

namespace Database\Seeders;

use App\Domain\AccessControl\Commands\CreateRole;
use App\Domain\EventSourcing\CommandBus;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * docs/05-user-roles.md のユーザー種別に対応する既定ロールをCommand(操作者null)で作成する。
 * 既にあるロールは飛ばす。
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $names = [
            Role::EMPLOYEE => '一般社員',
            Role::BACKOFFICE_STAFF => 'バックオフィス担当者',
            Role::ACCOUNTING_STAFF => '経理担当者',
            Role::GENERAL_AFFAIRS_STAFF => '総務担当者',
            Role::HR_STAFF => '人事担当者',
            Role::ADMIN => 'システム管理者',
        ];

        foreach ($names as $code => $name) {
            if (! DB::table('roles')->where('code', $code)->exists()) {
                app(CommandBus::class)->dispatch(new CreateRole($code, $name, null, null));
            }
        }
    }
}
