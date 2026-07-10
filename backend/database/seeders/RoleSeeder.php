<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * docs/05-user-roles.md のユーザー種別に対応する既定ロールを作成する。
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
            Role::query()->firstOrCreate(['code' => $code], ['name' => $name]);
        }
    }
}
