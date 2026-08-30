<?php

namespace App\Http\Controllers\Api;

use App\Domain\AccessControl\Commands\ChangeRoleFeatures;
use App\Domain\AccessControl\Commands\ChangeRolePermissions;
use App\Domain\AccessControl\Commands\CreateRole;
use App\Domain\AccessControl\Commands\CreateRoleAssignment;
use App\Domain\AccessControl\Commands\RemoveRoleAssignment;
use App\Domain\AccessControl\Commands\RemoveUserFeatureSuspension;
use App\Domain\AccessControl\Commands\SuspendUserFeature;
use App\Domain\AccessControl\Commands\UpdateRole;
use App\Domain\AccessControl\Commands\UpdateRoleAssignment;
use App\Domain\AccessControl\Services\EffectiveAccessResolver;
use App\Domain\EventSourcing\CommandBus;
use App\Http\Controllers\Controller;
use App\Models\Feature;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\UserFeatureSuspension;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AccessControlController extends Controller
{
    public function __construct(private EffectiveAccessResolver $access) {}

    public function features(): JsonResponse
    {
        return response()->json(Feature::query()->whereNull('parent_feature_id')->with(['children' => fn ($query) => $query->orderBy('display_order')])->orderBy('display_order')->get());
    }

    public function permissions(): JsonResponse
    {
        return response()->json(Permission::query()->orderBy('resource')->orderBy('action')->get()->map(function (Permission $permission) {
            $permission->setAttribute('allowed_scope_types', $permission->allowedScopeTypes());

            return $permission;
        }));
    }

    public function roles(): JsonResponse
    {
        $roles = Role::query()->with('permissions', 'features')->orderBy('id')->get();
        $roles->each(fn (Role $role) => $role->permissions->each(
            fn (Permission $permission) => $permission->setAttribute('allowed_scope_types', $permission->allowedScopeTypes())
        ));

        return response()->json($roles);
    }

    public function roleAssignments(Request $request): JsonResponse
    {
        $query = RoleAssignment::query()->with(['role.permissions', 'scopeGroup', 'assigner']);
        if (! $this->hasGlobalAccess($request)) {
            $groups = $this->permittedGroupIds($request);
            $users = $this->permittedUserIds($request);
            $query->where(fn ($scope) => $scope
                ->where(fn ($group) => $group->where('subject_type', 'group')->whereIn('subject_id', $groups))
                ->orWhere(fn ($user) => $user->where('subject_type', 'user')->whereIn('subject_id', $users)));
        }

        return response()->json($query->orderByDesc('created_at')->get());
    }

    public function suspensions(Request $request): JsonResponse
    {
        $query = UserFeatureSuspension::query()->with(['user:id,name', 'feature:id,code,name']);
        if (! $this->hasGlobalAccess($request)) {
            $query->whereIn('user_id', $this->permittedUserIds($request));
        }

        return response()->json($query->orderByDesc('created_at')->get());
    }

    public function storeRole(Request $r, CommandBus $bus): JsonResponse
    {
        $this->assertGlobal($r);
        $d = $r->validate(['code' => ['required', 'string', 'max:100'], 'name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string']]);
        $bus->dispatch(new CreateRole($d['code'], $d['name'], $d['description'] ?? null, $r->user()->id));

        return response()->json([], 201);
    }

    public function updateRole(Request $r, int $role, CommandBus $bus): JsonResponse
    {
        $this->assertGlobal($r);
        $current = Role::query()->findOrFail($role);
        $d = $r->validate(['name' => ['sometimes', 'string', 'max:255'], 'description' => ['nullable', 'string'], 'status' => ['sometimes', Rule::in(['active', 'inactive'])]]);
        $bus->dispatch(new UpdateRole($role, $d['name'] ?? $current->name, array_key_exists('description', $d) ? $d['description'] : $current->description, $d['status'] ?? $current->status, $r->user()->id));

        return response()->json([]);
    }

    public function cloneRole(Request $r, int $role, CommandBus $bus): JsonResponse
    {
        $this->assertGlobal($r);
        $source = Role::query()->with('permissions')->findOrFail($role);
        $data = $r->validate(['code' => ['required', 'string', 'max:100'], 'name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string']]);
        $bus->dispatch(new CreateRole($data['code'], $data['name'], $data['description'] ?? $source->description, $r->user()->id));
        $created = Role::query()->where('code', $data['code'])->firstOrFail();
        $bus->dispatch(new ChangeRolePermissions($created->id, $source->permissions->pluck('id')->all(), $r->user()->id));

        return response()->json(['id' => $created->id], 201);
    }

    public function suspendFeature(Request $r, CommandBus $bus): JsonResponse
    {
        $d = $r->validate(['user_id' => ['required', 'uuid', 'exists:users,id'], 'feature_id' => ['required', 'integer', 'exists:features,id'], 'reason' => ['required', 'string', 'max:1000'], 'starts_at' => ['nullable', 'date'], 'ends_at' => ['nullable', 'date']]);
        $this->assertUserAllowed($r, $d['user_id']);
        $bus->dispatch(new SuspendUserFeature($d['user_id'], $d['feature_id'], $d['reason'], $d['starts_at'] ?? null, $d['ends_at'] ?? null, $r->user()->id));

        return response()->json([], 201);
    }

    public function removeSuspension(Request $r, string $suspension, CommandBus $bus): JsonResponse
    {
        $record = UserFeatureSuspension::query()->findOrFail($suspension);
        $this->assertUserAllowed($r, $record->user_id);
        $bus->dispatch(new RemoveUserFeatureSuspension($suspension, $r->user()->id));

        return response()->json([], 200);
    }

    public function storeRoleAssignment(Request $r, CommandBus $bus): JsonResponse
    {
        $d = $r->validate(['subject_type' => ['required', Rule::in(['user', 'group'])], 'subject_id' => ['required', 'uuid'], 'role_id' => ['required', 'integer'], 'scope_type' => ['required', Rule::in(['global', 'group', 'self', 'approval_task'])], 'scope_group_id' => ['nullable', 'uuid'], 'include_descendants' => ['boolean'], 'starts_at' => ['nullable', 'date'], 'ends_at' => ['nullable', 'date']]);
        $this->assertAssignmentAllowed($r, $d['subject_type'], $d['subject_id'], $d['scope_type'], $d['scope_group_id'] ?? null);
        $id = (string) Str::uuid();
        $bus->dispatch(new CreateRoleAssignment($id, $d['subject_type'], $d['subject_id'], $d['role_id'], $d['scope_type'], $d['scope_group_id'] ?? null, $d['include_descendants'] ?? false, $d['starts_at'] ?? null, $d['ends_at'] ?? null, $r->user()->id));

        return response()->json(['id' => $id], 201);
    }

    public function updateRoleAssignment(Request $r, string $assignment, CommandBus $bus): JsonResponse
    {
        $current = RoleAssignment::query()->findOrFail($assignment);
        $d = $r->validate(['scope_type' => ['sometimes', Rule::in(['global', 'group', 'self', 'approval_task'])], 'scope_group_id' => ['nullable', 'uuid'], 'include_descendants' => ['boolean'], 'starts_at' => ['nullable', 'date'], 'ends_at' => ['nullable', 'date']]);
        $this->assertAssignmentAllowed($r, $current->subject_type, $current->subject_id, $d['scope_type'] ?? $current->scope_type, array_key_exists('scope_group_id', $d) ? $d['scope_group_id'] : $current->scope_group_id);
        $bus->dispatch(new UpdateRoleAssignment($assignment, $d['scope_type'] ?? $current->scope_type, array_key_exists('scope_group_id', $d) ? $d['scope_group_id'] : $current->scope_group_id, $d['include_descendants'] ?? $current->include_descendants, array_key_exists('starts_at', $d) ? $d['starts_at'] : $current->starts_at?->toISOString(), array_key_exists('ends_at', $d) ? $d['ends_at'] : $current->ends_at?->toISOString(), $r->user()->id));

        return response()->json([]);
    }

    public function destroyRoleAssignment(Request $r, string $assignment, CommandBus $bus): JsonResponse
    {
        $current = RoleAssignment::query()->findOrFail($assignment);
        $this->assertAssignmentAllowed($r, $current->subject_type, $current->subject_id, $current->scope_type, $current->scope_group_id);
        $bus->dispatch(new RemoveRoleAssignment($assignment, $r->user()->id));

        return response()->json([], 200);
    }

    public function updateRolePermissions(Request $r, int $role, CommandBus $bus): JsonResponse
    {
        $this->assertGlobal($r);
        $d = $r->validate(['permission_ids' => ['array'], 'permission_ids.*' => ['integer', 'distinct']]);
        $bus->dispatch(new ChangeRolePermissions($role, $d['permission_ids'] ?? [], $r->user()->id));

        return response()->json([], 200);
    }

    public function updateRoleFeatures(Request $r, int $role, CommandBus $bus): JsonResponse
    {
        $this->assertGlobal($r);
        $d = $r->validate(['feature_ids' => ['array'], 'feature_ids.*' => ['integer', 'distinct']]);
        $bus->dispatch(new ChangeRoleFeatures($role, $d['feature_ids'] ?? [], $r->user()->id));

        return response()->json([], 200);
    }

    private function assertGlobal(Request $request): void
    {
        abort_unless($this->hasGlobalAccess($request), 403);
    }

    private function permittedGroupIds(Request $request): Collection
    {
        return $this->access->permittedGroupIds($request->user(), $this->permissionCode($request));
    }

    private function permittedUserIds(Request $request): Collection
    {
        return DB::table('memberships')
            ->whereIn('group_id', $this->permittedGroupIds($request))
            ->pluck('user_id')
            ->push($request->user()->id)
            ->unique();
    }

    private function assertGroupAllowed(Request $request, string $groupId): void
    {
        if ($this->hasGlobalAccess($request)) {
            return;
        }
        abort_unless($this->permittedGroupIds($request)->contains($groupId), 403);
    }

    private function assertUserAllowed(Request $request, string $userId): void
    {
        if ($this->hasGlobalAccess($request)) {
            return;
        }
        abort_unless($this->permittedUserIds($request)->contains($userId), 403);
    }

    private function assertAssignmentAllowed(Request $request, string $subjectType, string $subjectId, string $scopeType, ?string $scopeGroupId): void
    {
        if ($this->hasGlobalAccess($request)) {
            return;
        }
        abort_if($scopeType === 'global', 403);
        $subjectType === 'group' ? $this->assertGroupAllowed($request, $subjectId) : $this->assertUserAllowed($request, $subjectId);
        if ($scopeGroupId !== null) {
            $this->assertGroupAllowed($request, $scopeGroupId);
        }
    }

    private function hasGlobalAccess(Request $request): bool
    {
        return $this->access->hasGlobalPermission($request->user(), $this->permissionCode($request));
    }

    private function permissionCode(Request $request): string
    {
        return (string) $request->attributes->get('effective_permission_code', 'user.manage');
    }
}
