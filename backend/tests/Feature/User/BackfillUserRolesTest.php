<?php

namespace Tests\Feature\User;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\User\Commands\BackfillUserRoles;
use App\Domain\User\Projectors\UserProjector;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

class BackfillUserRolesTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfills_existing_role_assignments_once_and_restores_them_on_replay(): void
    {
        $adminRole = Role::query()->create(['code' => Role::ADMIN, 'name' => '管理者']);
        $employeeRole = Role::query()->create(['code' => Role::EMPLOYEE, 'name' => '従業員']);
        $admin = User::factory()->create();
        $employee = User::factory()->create();
        User::factory()->create();
        $admin->roles()->attach($adminRole);
        $employee->roles()->attach($employeeRole);

        $count = app(CommandBus::class)->dispatch(new BackfillUserRoles);

        $this->assertSame(2, $count);
        $this->assertSame(2, EloquentStoredEvent::query()
            ->where('event_class', 'user.roles_migrated_from_legacy')
            ->count());
        $this->assertSame(0, app(CommandBus::class)->dispatch(new BackfillUserRoles));

        $admin->roles()->detach();
        $employee->roles()->detach();

        $this->artisan('event-sourcing:replay', [
            'projector' => [UserProjector::class],
            '--force' => true,
        ])->assertSuccessful();

        $this->assertSame([Role::ADMIN], $admin->fresh()->roles->pluck('code')->all());
        $this->assertSame([Role::EMPLOYEE], $employee->fresh()->roles->pluck('code')->all());
    }
}
