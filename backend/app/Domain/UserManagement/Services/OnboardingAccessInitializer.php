<?php

namespace App\Domain\UserManagement\Services;

use Database\Seeders\AccessControlSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\UserManagementSeeder;
use Illuminate\Support\Facades\DB;

final class OnboardingAccessInitializer
{
    public function initialize(): void
    {
        DB::transaction(function (): void {
            app(RoleSeeder::class)->run();
            app(UserManagementSeeder::class)->run();
            app(AccessControlSeeder::class)->run();
        });
    }
}