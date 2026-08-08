<?php

namespace App\Http\Controllers\Api;

use App\Domain\AccessControl\Commands\AssignFeatureToGroup;
use App\Domain\AccessControl\Commands\ChangeRolePermissions;
use App\Domain\AccessControl\Commands\CreateRole;
use App\Domain\AccessControl\Commands\CreateRoleAssignment;
use App\Domain\AccessControl\Commands\RemoveFeatureFromGroup;
use App\Domain\AccessControl\Commands\RemoveRoleAssignment;
use App\Domain\AccessControl\Commands\RemoveUserFeatureSuspension;
use App\Domain\AccessControl\Commands\SuspendUserFeature;
use App\Domain\AccessControl\Commands\UpdateRole;
use App\Domain\AccessControl\Commands\UpdateRoleAssignment;
use App\Domain\EventSourcing\CommandBus;
use App\Http\Controllers\Controller;
use App\Models\Feature;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\UserFeatureSuspension;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AccessControlController extends Controller
{
    public function features(): JsonResponse
    {
        return response()->json(Feature::query()->with('children')->orderBy('id')->get());
    }

    public function permissions(): JsonResponse
    {
        return response()->json(Permission::query()->orderBy('resource')->orderBy('action')->get());
    }

    public function roles(): JsonResponse
    {
        return response()->json(Role::query()->with('permissions')->orderBy('id')->get());
    }

    public function roleAssignments(): JsonResponse
    {
        return response()->json(RoleAssignment::query()->with(['role.permissions', 'scopeGroup', 'assigner'])->orderByDesc('created_at')->get());
    }

    public function suspensions(): JsonResponse
    {
        return response()->json(UserFeatureSuspension::query()->with(['user:id,name', 'feature:id,code,name'])->orderByDesc('created_at')->get());
    }

    public function storeRole(Request $r, CommandBus $bus): JsonResponse
    {
        $d = $r->validate(['code' => ['required', 'string', 'max:100'], 'name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string']]);
        $bus->dispatch(new CreateRole($d['code'], $d['name'], $d['description'] ?? null, $r->user()->id));

        return response()->json([], 201);
    }

    public function updateRole(Request $r, int $role, CommandBus $bus): JsonResponse
    {
        $current = Role::query()->findOrFail($role);
        $d = $r->validate(['name' => ['sometimes', 'string', 'max:255'], 'description' => ['nullable', 'string'], 'status' => ['sometimes', Rule::in(['active', 'inactive'])]]);
        $bus->dispatch(new UpdateRole($role, $d['name'] ?? $current->name, array_key_exists('description', $d) ? $d['description'] : $current->description, $d['status'] ?? $current->status, $r->user()->id));

        return response()->json([]);
    }

    public function assignFeature(Request $r, CommandBus $bus): JsonResponse
    {
        $d = $r->validate(['feature_id' => ['required', 'integer']]);
        $bus->dispatch(new AssignFeatureToGroup($r->route('group'), $d['feature_id'], $r->user()->id));

        return response()->json([], 201);
    }

    public function removeFeature(Request $r, string $group, int $feature, CommandBus $bus): JsonResponse
    {
        $bus->dispatch(new RemoveFeatureFromGroup($group, $feature, $r->user()->id));

        return response()->json([], 200);
    }

    public function suspendFeature(Request $r, CommandBus $bus): JsonResponse
    {
        $d = $r->validate(['user_id' => ['required', 'uuid', 'exists:users,id'], 'feature_id' => ['required', 'integer', 'exists:features,id'], 'reason' => ['required', 'string', 'max:1000'], 'starts_at' => ['nullable', 'date'], 'ends_at' => ['nullable', 'date']]);
        $bus->dispatch(new SuspendUserFeature($d['user_id'], $d['feature_id'], $d['reason'], $d['starts_at'] ?? null, $d['ends_at'] ?? null, $r->user()->id));

        return response()->json([], 201);
    }

    public function removeSuspension(Request $r, string $suspension, CommandBus $bus): JsonResponse
    {
        $bus->dispatch(new RemoveUserFeatureSuspension($suspension, $r->user()->id));

        return response()->json([], 200);
    }

    public function storeRoleAssignment(Request $r, CommandBus $bus): JsonResponse
    {
        $d = $r->validate(['subject_type' => ['required', Rule::in(['user', 'group'])], 'subject_id' => ['required', 'uuid'], 'role_id' => ['required', 'integer'], 'scope_type' => ['required', Rule::in(['global', 'group', 'self', 'approval_task'])], 'scope_group_id' => ['nullable', 'uuid'], 'include_descendants' => ['boolean'], 'starts_at' => ['nullable', 'date'], 'ends_at' => ['nullable', 'date']]);
        $id = (string) Str::uuid();
        $bus->dispatch(new CreateRoleAssignment($id, $d['subject_type'], $d['subject_id'], $d['role_id'], $d['scope_type'], $d['scope_group_id'] ?? null, $d['include_descendants'] ?? false, $d['starts_at'] ?? null, $d['ends_at'] ?? null, $r->user()->id));

        return response()->json(['id' => $id], 201);
    }

    public function updateRoleAssignment(Request $r, string $assignment, CommandBus $bus): JsonResponse
    {
        $current = RoleAssignment::query()->findOrFail($assignment);
        $d = $r->validate(['scope_type' => ['sometimes', Rule::in(['global', 'group', 'self', 'approval_task'])], 'scope_group_id' => ['nullable', 'uuid'], 'include_descendants' => ['boolean'], 'starts_at' => ['nullable', 'date'], 'ends_at' => ['nullable', 'date']]);
        $bus->dispatch(new UpdateRoleAssignment($assignment, $d['scope_type'] ?? $current->scope_type, array_key_exists('scope_group_id', $d) ? $d['scope_group_id'] : $current->scope_group_id, $d['include_descendants'] ?? $current->include_descendants, array_key_exists('starts_at', $d) ? $d['starts_at'] : $current->starts_at?->toISOString(), array_key_exists('ends_at', $d) ? $d['ends_at'] : $current->ends_at?->toISOString(), $r->user()->id));

        return response()->json([]);
    }

    public function destroyRoleAssignment(Request $r, string $assignment, CommandBus $bus): JsonResponse
    {
        $bus->dispatch(new RemoveRoleAssignment($assignment, $r->user()->id));

        return response()->json([], 200);
    }

    public function updateRolePermissions(Request $r, int $role, CommandBus $bus): JsonResponse
    {
        $d = $r->validate(['permission_ids' => ['array'], 'permission_ids.*' => ['integer', 'distinct']]);
        $bus->dispatch(new ChangeRolePermissions($role,$d['permission_ids'] ?? [],$r->user()->id));

        return response()->json([],200);
    }
}
