<?php

namespace App\Http\Controllers\Api;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\UserManagement\Commands\AddMembership;
use App\Domain\UserManagement\Commands\ApplyExternalHrImport;
use App\Domain\UserManagement\Commands\ApplyMembershipChange;
use App\Domain\UserManagement\Commands\CancelMembershipChange;
use App\Domain\UserManagement\Commands\ChangeFieldAuthority;
use App\Domain\UserManagement\Commands\CreateGroup;
use App\Domain\UserManagement\Commands\CreateGroupType;
use App\Domain\UserManagement\Commands\CreateMembershipChangeDraft;
use App\Domain\UserManagement\Commands\LinkExternalIdentity;
use App\Domain\UserManagement\Commands\RemoveMembership;
use App\Domain\UserManagement\Commands\ScheduleExistingMembershipChange;
use App\Domain\UserManagement\Commands\ScheduleMembershipChange;
use App\Domain\UserManagement\Commands\UnlinkExternalIdentity;
use App\Domain\UserManagement\Commands\UpdateGroup;
use App\Domain\UserManagement\Commands\UpdateGroupType;
use App\Domain\UserManagement\Commands\UpdateMembershipChange;
use App\Http\Controllers\Controller;
use App\Models\ExternalIdentity;
use App\Models\Group;
use App\Models\GroupType;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserManagementController extends Controller
{
    public function groupTypes(): JsonResponse
    {
        return response()->json(GroupType::query()->orderBy('display_order')->orderBy('id')->get());
    }

    public function groups(): JsonResponse
    {
        return response()->json(Group::query()->with(['type', 'parent', 'features', 'memberships.user:id,name,email'])->withCount('memberships')->orderBy('name')->get());
    }

    public function changeSets(): JsonResponse
    {
        $sets = DB::table('membership_change_sets')->orderByDesc('effective_at')->get();
        $items = DB::table('membership_change_items')->whereIn('change_set_id', $sets->pluck('id'))->get()->groupBy('change_set_id');

        return response()->json($sets->map(fn ($set) => (array) $set + ['items' => $items->get($set->id, collect())->values()]));
    }

    public function externalIdentities(): JsonResponse
    {
        return response()->json(ExternalIdentity::query()->with('user:id,name,email')->orderByDesc('linked_at')->get());
    }

    public function fieldAuthorities(): JsonResponse
    {
        return response()->json(DB::table('field_authorities')->orderBy('field_key')->get());
    }

    public function storeGroup(Request $r, CommandBus $bus): JsonResponse
    {
        $d = $r->validate(['group_type_id' => ['required', 'integer'], 'name' => ['required', 'string', 'max:255'], 'code' => ['required', 'string', 'max:100'], 'description' => ['nullable', 'string'], 'parent_group_id' => ['nullable', 'uuid']]);
        $id = (string) Str::uuid();
        $bus->dispatch(new CreateGroup($id, $d['group_type_id'], $d['name'], $d['code'], $d['description'] ?? null, $d['parent_group_id'] ?? null, $r->user()->id));

        return response()->json(['id' => $id], 201);
    }

    public function storeGroupType(Request $r, CommandBus $bus): JsonResponse
    {
        $d = $r->validate(['code' => ['required', 'string', 'max:100'], 'name' => ['required', 'string', 'max:255'], 'display_order' => ['integer', 'min:0'], 'membership_limit_type' => ['required', Rule::in(['unlimited', 'limited'])], 'max_memberships_per_user' => ['nullable', 'integer', 'min:1'], 'primary_membership_required' => ['boolean'], 'max_primary_memberships' => ['nullable', 'integer', 'min:1']]);
        $bus->dispatch(new CreateGroupType(strtoupper($d['code']), $d['name'], $d['display_order'] ?? 0, $d['membership_limit_type'], $d['max_memberships_per_user'] ?? null, $d['primary_membership_required'] ?? false, $d['max_primary_memberships'] ?? null, $r->user()->id));

        return response()->json([], 201);
    }

    public function updateGroupType(Request $r, int $groupType, CommandBus $bus): JsonResponse
    {
        $current = GroupType::query()->findOrFail($groupType);
        $d = $r->validate(['name' => ['sometimes', 'string', 'max:255'], 'display_order' => ['integer', 'min:0'], 'status' => ['sometimes', Rule::in(['active', 'inactive'])], 'membership_limit_type' => ['sometimes', Rule::in(['unlimited', 'limited'])], 'max_memberships_per_user' => ['nullable', 'integer', 'min:1'], 'primary_membership_required' => ['boolean'], 'max_primary_memberships' => ['nullable', 'integer', 'min:1']]);
        $bus->dispatch(new UpdateGroupType($groupType, $d['name'] ?? $current->name, $d['display_order'] ?? $current->display_order, $d['status'] ?? $current->status, $d['membership_limit_type'] ?? $current->membership_limit_type, array_key_exists('max_memberships_per_user', $d) ? $d['max_memberships_per_user'] : $current->max_memberships_per_user, $d['primary_membership_required'] ?? $current->primary_membership_required, array_key_exists('max_primary_memberships', $d) ? $d['max_primary_memberships'] : $current->max_primary_memberships, $r->user()->id));

        return response()->json([]);
    }

    public function updateGroup(Request $r, string $group, CommandBus $bus): JsonResponse
    {
        $current = Group::query()->findOrFail($group);
        $d = $r->validate(['name' => ['sometimes', 'string', 'max:255'], 'code' => ['sometimes', 'string', 'max:100'], 'description' => ['nullable', 'string'], 'parent_group_id' => ['nullable', 'uuid'], 'status' => ['sometimes', Rule::in(['active', 'planned_inactive', 'inactive'])]]);
        $bus->dispatch(new UpdateGroup($group, $d['name'] ?? $current->name, $d['code'] ?? $current->code, array_key_exists('description', $d) ? $d['description'] : $current->description, array_key_exists('parent_group_id', $d) ? $d['parent_group_id'] : $current->parent_group_id, $d['status'] ?? $current->status, $r->user()->id));

        return response()->json([], 200);
    }

    public function storeMembership(Request $r, CommandBus $bus): JsonResponse
    {
        $d = $r->validate(['user_id' => ['required', 'uuid', 'exists:users,id'], 'group_id' => ['required', 'uuid'], 'membership_kind' => ['required', Rule::in(['primary', 'secondary', 'member', 'temporary', 'observer'])], 'is_primary' => ['boolean']]);
        $bus->dispatch(new AddMembership($d['user_id'], $d['group_id'], $d['membership_kind'], $d['is_primary'] ?? false, $r->user()->id));

        return response()->json([], 201);
    }

    public function destroyMembership(Request $r, string $user, string $group, CommandBus $bus): JsonResponse
    {
        $bus->dispatch(new RemoveMembership($user, $group, $r->user()->id));

        return response()->json([], 200);
    }

    public function scheduleChange(Request $r, CommandBus $bus): JsonResponse
    {
        $d = $this->validatedChangeSet($r);
        $id = (string) Str::uuid();
        $effectiveAt = CarbonImmutable::parse($d['effective_at'])->utc()->format('Y-m-d H:i:s');
        $bus->dispatch(new ScheduleMembershipChange($id, $d['user_id'], $effectiveAt, $d['source_type'], $d['items'], $d['note'] ?? null, $r->user()->id));

        return response()->json(['id' => $id], 201);
    }

    public function draftChange(Request $r, CommandBus $bus): JsonResponse
    {
        $d = $this->validatedChangeSet($r);
        $id = (string) Str::uuid();
        $bus->dispatch(new CreateMembershipChangeDraft($id, $d['user_id'], CarbonImmutable::parse($d['effective_at'])->utc()->format('Y-m-d H:i:s'), $d['source_type'], $d['items'], $d['note'] ?? null, $r->user()->id));

        return response()->json(['id' => $id], 201);
    }

    public function updateChange(Request $r, string $changeSet, CommandBus $bus): JsonResponse
    {
        $d = $this->validatedChangeSet($r);
        $bus->dispatch(new UpdateMembershipChange($changeSet, $d['user_id'], CarbonImmutable::parse($d['effective_at'])->utc()->format('Y-m-d H:i:s'), $d['source_type'], $d['items'], $d['note'] ?? null, $r->user()->id));

        return response()->json([]);
    }

    public function applyChange(Request $r, string $changeSet, CommandBus $bus): JsonResponse
    {
        $bus->dispatch(new ApplyMembershipChange($changeSet, $r->user()->id));

        return response()->json([], 200);
    }

    public function scheduleExistingChange(Request $r, string $changeSet, CommandBus $bus): JsonResponse
    {
        $bus->dispatch(new ScheduleExistingMembershipChange($changeSet, $r->user()->id));

        return response()->json([]);
    }

    public function cancelChange(Request $r, string $changeSet, CommandBus $bus): JsonResponse
    {
        $bus->dispatch(new CancelMembershipChange($changeSet, $r->user()->id));

        return response()->json([], 200);
    }

    public function linkExternalIdentity(Request $r, string $user, CommandBus $bus): JsonResponse
    {
        $d = $r->validate(['provider' => ['required', 'string', 'max:100'], 'external_tenant_id' => ['nullable', 'string', 'max:255'], 'external_subject_id' => ['required', 'string', 'max:255'], 'external_code' => ['nullable', 'string', 'max:255'], 'email' => ['nullable', 'email', 'max:255']]);
        $bus->dispatch(new LinkExternalIdentity($user, strtoupper($d['provider']), $d['external_tenant_id'] ?? null, $d['external_subject_id'], $d['external_code'] ?? null, $d['email'] ?? null, $r->user()->id));

        return response()->json([], 201);
    }

    public function unlinkExternalIdentity(Request $r, int $identity, CommandBus $bus): JsonResponse
    {
        $bus->dispatch(new UnlinkExternalIdentity($identity, $r->user()->id));

        return response()->json([], 200);
    }

    public function updateFieldAuthority(Request $r, string $fieldKey, CommandBus $bus): JsonResponse
    {
        $d = $r->validate(['authority_type' => ['required', Rule::in(['LOCAL', 'EXTERNAL_HR'])], 'provider' => ['nullable', 'string', 'max:100']]);
        $bus->dispatch(new ChangeFieldAuthority($fieldKey, $d['authority_type'], $d['provider'] ?? null, $r->user()->id));

        return response()->json([], 200);
    }

    public function externalHrImportPreview(Request $r): JsonResponse
    {
        $r->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']]);
        $file = new \SplFileObject($r->file('file')->getRealPath());
        $file->setFlags(\SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY);
        $headers = null;
        $rows = [];
        $allowed = DB::table('field_authorities')->where('authority_type', 'EXTERNAL_HR')->pluck('field_key')->all();
        foreach ($file as $values) {
            if (! $values || $values === [null]) {
                continue;
            } if ($headers === null) {
                $headers = array_map(fn ($v) => trim((string) $v, "\xEF\xBB\xBF \t\r\n"), $values);

                continue;
            } $values = array_pad($values, count($headers), null);
            $source = array_combine($headers, array_slice($values, 0, count($headers)));
            $subject = trim((string) ($source['external_subject_id'] ?? ''));
            if ($subject === '') {
                continue;
            } $identity = DB::table('external_identities')->where('provider', 'EXTERNAL_HR')->where('external_subject_id', $subject)->first();
            $user = $identity ? DB::table('users')->where('id', $identity->user_id)->first() : null;
            if (! $user && ! empty($source['employee_number'])) {
                $user = DB::table('users')->where('employee_number', $source['employee_number'])->first();
            } if (! $user && ! empty($source['email'])) {
                $user = DB::table('users')->where('email', $source['email'])->first();
            } $changes = [];
            $diff = [];
            foreach ($allowed as $field) {
                $column = $field === 'display_name' ? 'display_name' : $field;
                if (! array_key_exists($column, $source) || $source[$column] === '') {
                    continue;
                } $value = $source[$column];
                $changes[$field] = $value;
                $modelField = $field === 'display_name' ? 'name' : $field;
                $before = $user?->$modelField;
                if ((string) $before !== (string) $value) {
                    $diff[$field] = ['before' => $before, 'after' => $value];
                }
            } $rows[] = ['user_id' => $user?->id ?? (string) Str::uuid(), 'external_subject_id' => $subject, 'changes' => $changes, 'diff' => $diff, 'group_code' => $source['group_code'] ?? null, 'effective_at' => $source['effective_at'] ?? now()->toISOString(), 'is_new' => $user === null];
        }

        return response()->json(['rows' => $rows, 'summary' => ['total' => count($rows), 'new' => collect($rows)->where('is_new', true)->count(), 'changed' => collect($rows)->filter(fn ($row) => count($row['diff']) > 0 || $row['group_code'])->count()]]);
    }

    public function applyExternalHrImport(Request $r, CommandBus $bus): JsonResponse
    {
        $d = $r->validate(['rows' => ['required', 'array', 'min:1'], 'rows.*.user_id' => ['required', 'uuid'], 'rows.*.external_subject_id' => ['required', 'string', 'max:255'], 'rows.*.changes' => ['array'], 'rows.*.group_code' => ['nullable', 'string', 'max:100'], 'rows.*.effective_at' => ['nullable', 'date']]);
        $id = (string) Str::uuid();
        $bus->dispatch(new ApplyExternalHrImport($id, $d['rows'], $r->user()->id));

        return response()->json(['id' => $id], 201);
    }

    private function validatedChangeSet(Request $r): array
    {
        return $r->validate(['user_id' => ['required', 'uuid', 'exists:users,id'], 'effective_at' => ['required', 'date'], 'source_type' => ['required', Rule::in(['manual', 'csv_import', 'external_hr', 'api'])], 'note' => ['nullable', 'string'], 'items' => ['required', 'array', 'min:1'], 'items.*.operation' => ['required', Rule::in(['add', 'remove', 'replace', 'set_primary'])], 'items.*.group_type_id' => ['required', 'integer'], 'items.*.from_group_id' => ['nullable', 'uuid'], 'items.*.to_group_id' => ['nullable', 'uuid'], 'items.*.target_group_id' => ['nullable', 'uuid'], 'items.*.is_primary' => ['boolean']]);
    }
}
