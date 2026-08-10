<?php

namespace Tests\Feature\AccessControl;

use App\Domain\AccessControl\Services\EffectiveAccessResolver;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\UserManagementSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PermissionCatalogIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([UserManagementSeeder::class, AccessControlSeeder::class]);
    }

    public function test_every_permission_used_by_a_route_exists_in_the_catalog(): void
    {
        $routePermissions = collect(Route::getRoutes()->getRoutes())
            ->flatMap(fn ($route) => $route->gatherMiddleware())
            ->filter(fn (string $middleware) => str_starts_with($middleware, 'permission:'))
            ->map(fn (string $middleware) => explode(',', substr($middleware, strlen('permission:')))[0])
            ->unique()
            ->sort()
            ->values();

        $catalogPermissions = DB::table('permissions')->pluck('code')->sort()->values();

        $this->assertNotEmpty($routePermissions);
        $this->assertSame([], $routePermissions->diff($catalogPermissions)->values()->all());
    }

    public function test_standard_groups_receive_the_migrated_feature_defaults(): void
    {
        $assignments = DB::table('group_feature_assignments as assignment')
            ->join('groups', 'groups.id', '=', 'assignment.group_id')
            ->join('features', 'features.id', '=', 'assignment.feature_id')
            ->whereIn('groups.code', ['ALL_USERS', 'BACKOFFICE_USERS'])
            ->orderBy('features.display_order')
            ->get(['groups.code as group_code', 'features.code as feature_code'])
            ->groupBy('group_code')
            ->map(fn ($rows) => $rows->pluck('feature_code')->all());

        $this->assertSame([
            'attendance', 'attendance.clock', 'attendance.entry', 'attendance.timesheet',
            'workflow', 'workflow.requests', 'paid_leave', 'paid_leave.requests',
            'backoffice.expenses',
        ], $assignments['ALL_USERS']);
        $this->assertSame(['backoffice.tasks'], $assignments['BACKOFFICE_USERS']);
    }

    public function test_system_administrator_group_grants_and_revokes_all_catalog_permissions(): void
    {
        $user = User::factory()->create();
        $adminGroupId = DB::table('groups')->where('code', 'SYSTEM_ADMINISTRATORS')->value('id');
        DB::table('memberships')->insert([
            'user_id' => $user->id,
            'group_id' => $adminGroupId,
            'membership_kind' => 'member',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resolver = app(EffectiveAccessResolver::class);
        $catalogPermissions = DB::table('permissions')->pluck('code')->sort()->values()->all();

        $this->assertSame($catalogPermissions, $resolver->permissions($user)->all());
        $this->assertContains('administration', $resolver->features($user)->all());
        $this->assertContains('administration.users', $resolver->features($user)->all());
        $this->assertContains('administration.settings', $resolver->features($user)->all());

        DB::table('memberships')->where('user_id', $user->id)->where('group_id', $adminGroupId)->delete();

        $this->assertNotContains('user.view', $resolver->permissions($user)->all());
        $this->assertNotContains('administration', $resolver->features($user)->all());
    }

    public function test_authentication_response_does_not_expose_legacy_user_roles(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonMissingPath('roles')
            ->assertJsonStructure(['effective_features', 'effective_permissions']);

        $this->assertFalse(DB::getSchemaBuilder()->hasTable('role_user'));
    }
}
