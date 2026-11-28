<?php

namespace App\Http\Controllers\Api;

use App\Domain\AccessControl\Services\EffectiveAccessResolver;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\UserManagement\Commands\AddMembership;
use App\Domain\UserManagement\Commands\CreateUser;
use App\Domain\UserManagement\Commands\SetPaidLeaveAutoGrantEnabled;
use App\Domain\UserManagement\Commands\SetSpecialLeaveAutoGrantEnabled;
use App\Domain\UserManagement\Commands\SetUserHireDate;
use App\Domain\UserManagement\Commands\SetUserTerminationDate;
use App\Domain\UserManagement\Commands\SetUserUsageStartDate;
use App\Domain\UserManagement\Commands\UpdateUserDetails;
use App\Domain\UserManagement\Commands\UpdateUserProfile;
use App\Domain\UserManagement\Services\FieldAuthorityService;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Http\Resources\UserSearchResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

/**
 * UC-M001: 権限を設定する / ユーザー一覧管理。
 */
#[OA\Tag(name: 'ユーザー', description: 'ユーザー・権限管理')]
class UserController extends Controller
{
    public function store(Request $request, CommandBus $commandBus): JsonResponse
    {
        $attributes = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'employee_number' => ['nullable', 'string', 'max:100', 'unique:users,employee_number'],
            'department' => ['nullable', 'string', 'max:255'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'employment_status' => ['sometimes', 'string', 'max:100'],
            'account_status' => ['sometimes', 'in:pending,active,suspended,leave,retired,disabled'],
            'group_id' => ['nullable', 'uuid', 'exists:groups,id'],
        ]);
        $groupId = $attributes['group_id'] ?? null;
        unset($attributes['group_id']);
        $resolver = app(EffectiveAccessResolver::class);
        if (! $resolver->hasGlobalPermission($request->user(), 'user.create')) {
            abort_unless($groupId && $resolver->permittedGroupIds($request->user(), 'user.create')->contains($groupId), 403);
        }
        app(FieldAuthorityService::class)->assertLocallyEditable(array_keys($attributes));
        $id = (string) Str::uuid();
        $user = $commandBus->dispatch(new CreateUser($id, [
            ...$attributes,
            'employment_status' => $attributes['employment_status'] ?? 'active',
            'account_status' => $attributes['account_status'] ?? 'active',
        ], $request->user()->id));
        if ($groupId) {
            $commandBus->dispatch(new AddMembership($id, $groupId, 'member', false, $request->user()->id));
        }

        return (new UserResource($user->load(['externalIdentities', 'memberships.group.type'])))
            ->response()->setStatusCode(201);
    }

    public function update(Request $request, User $user, CommandBus $commandBus): UserResource
    {
        $changes = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'employee_number' => ['sometimes', 'nullable', 'string', 'max:100', 'unique:users,employee_number,'.$user->id],
            'department' => ['sometimes', 'nullable', 'string', 'max:255'],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'employment_status' => ['sometimes', 'string', 'max:100'],
            'account_status' => ['sometimes', 'in:pending,active,suspended,leave,retired,disabled'],
        ]);
        $commandBus->dispatch(new UpdateUserProfile($user->id, $changes, $request->user()->id));

        return new UserResource($user->refresh()->load(['externalIdentities', 'memberships.group.type']));
    }

    /**
     * 利用者詳細のまとめて保存。変更した項目だけを受け取り、UpdateUserDetails を1つdispatchする
     * (外部人事の権限・最終状態の検査は Handler。拒否は項目のキーを付けた422)
     * (docs/changesets/20261128-single-command-entrypoints/spec.md 論点1)。
     */
    #[OA\Put(
        path: '/users/{user}/details',
        operationId: 'users.updateDetails',
        summary: '利用者詳細をまとめて保存する(変更した項目だけを送る。すべて成功するか、すべて戻す)',
        tags: ['ユーザー'],
        parameters: [new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'profile', type: 'object', description: 'name(display_nameも可)・email・employee_number・department・job_title・employment_status・account_statusのうち変更したもの'),
                new OA\Property(property: 'hire_date', type: 'string', format: 'date', nullable: true),
                new OA\Property(property: 'termination_date', type: 'string', format: 'date', nullable: true),
                new OA\Property(property: 'usage_start_date', type: 'string', format: 'date', nullable: true),
                new OA\Property(property: 'paid_leave_auto_grant_enabled', type: 'boolean'),
                new OA\Property(property: 'special_leave_auto_grant_enabled', type: 'boolean'),
            ]),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Successful response'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
            new OA\Response(response: 403, description: 'Forbidden'),
            new OA\Response(response: 422, description: 'Validation error(errors.{項目キー})'),
        ],
    )]
    public function updateDetails(Request $request, User $user, CommandBus $commandBus, EffectiveAccessResolver $resolver): UserResource
    {
        // display_nameはPATCHのキー名(name)の別名として受け付ける。
        if (is_array($request->input('profile')) && $request->input('profile.display_name') !== null && ! $request->has('profile.name')) {
            $profile = $request->input('profile');
            $profile['name'] = $profile['display_name'];
            unset($profile['display_name']);
            $request->merge(['profile' => $profile]);
        }

        $data = $request->validate([
            'profile' => ['sometimes', 'array'],
            'profile.name' => ['sometimes', 'string', 'max:255'],
            'profile.email' => ['sometimes', 'nullable', 'email', 'max:255', 'unique:users,email,'.$user->id],
            'profile.employee_number' => ['sometimes', 'nullable', 'string', 'max:100', 'unique:users,employee_number,'.$user->id],
            'profile.department' => ['sometimes', 'nullable', 'string', 'max:255'],
            'profile.job_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'profile.employment_status' => ['sometimes', 'string', 'max:100'],
            'profile.account_status' => ['sometimes', 'in:pending,active,suspended,leave,retired,disabled'],
            'hire_date' => ['sometimes', 'nullable', 'date'],
            'termination_date' => ['sometimes', 'nullable', 'date'],
            'usage_start_date' => ['sometimes', 'nullable', 'date'],
            'paid_leave_auto_grant_enabled' => ['sometimes', 'boolean'],
            'special_leave_auto_grant_enabled' => ['sometimes', 'boolean'],
        ]);
        $fieldKeys = ['hire_date', 'termination_date', 'usage_start_date', 'paid_leave_auto_grant_enabled', 'special_leave_auto_grant_enabled'];
        $commandBus->dispatch(new UpdateUserDetails(
            $user->id,
            $data['profile'] ?? [],
            array_intersect_key($data, array_flip($fieldKeys)),
            $request->user()->id,
        ));

        return $this->show($user->refresh(), $resolver);
    }

    #[OA\Get(
        path: '/users',
        operationId: 'users.index',
        summary: 'ユーザー一覧を取得する',
        tags: ['ユーザー'],
        parameters: [
            new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'external_unlinked', in: 'query', required: false, description: 'Microsoft未連携のユーザーに絞り込む(1=はい、0=いいえ)', schema: new OA\Schema(type: 'integer', enum: [0, 1])),
            new OA\Parameter(name: 'external_hr', in: 'query', required: false, description: '外部HR管理のユーザーに絞り込む(1=はい、0=いいえ)', schema: new OA\Schema(type: 'integer', enum: [0, 1])),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful response'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function index(Request $request, EffectiveAccessResolver $resolver): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'group_id' => ['sometimes', 'uuid'],
            'group_type_id' => ['sometimes', 'integer'],
            'external_unlinked' => ['sometimes', 'boolean'],
            'external_hr' => ['sometimes', 'boolean'],
            'account_status' => ['sometimes', 'string'],
        ]);

        $query = User::query()
            ->with(['externalIdentities', 'memberships.group.type'])
            ->when($request->string('q')->toString(), fn ($query, $q) => $query->where(function ($sub) use ($q) {
                $sub->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%")->orWhere('employee_number', 'like', "%{$q}%");
            }))
            ->when($validated['group_id'] ?? null, fn ($query, $groupId) => $query->whereHas('memberships', fn ($q) => $q->where('group_id', $groupId)))
            ->when($validated['group_type_id'] ?? null, fn ($query, $typeId) => $query->whereHas('memberships.group', fn ($q) => $q->where('group_type_id', $typeId)))
            ->when(($validated['external_unlinked'] ?? false), fn ($query) => $query->whereDoesntHave('externalIdentities', fn ($q) => $q->where('provider', 'MICROSOFT_ENTRA')->where('status', 'active')))
            ->when(($validated['external_hr'] ?? false), fn ($query) => $query->whereHas('externalIdentities', fn ($q) => $q->where('provider', 'EXTERNAL_HR')->where('status', 'active')))
            ->when($validated['account_status'] ?? null, fn ($query, $status) => $query->where('account_status', $status));

        if (! $resolver->hasGlobalPermission($request->user(), 'user.view')) {
            $permittedGroupIds = $resolver->permittedGroupIds($request->user(), 'user.view');
            $query->where(function ($scope) use ($request, $permittedGroupIds) {
                $scope->whereKey($request->user()->id);
                if ($permittedGroupIds->isNotEmpty()) {
                    $scope->orWhereHas('memberships', fn ($membership) => $membership->whereIn('group_id', $permittedGroupIds));
                }
            });
        }

        $users = $query->orderBy('name')->paginate($validated['per_page'] ?? 50);

        $users->getCollection()->each(function (User $user) use ($resolver): void {
            $user->setAttribute('effective_features', $resolver->features($user)->all());
        });

        return UserResource::collection($users);
    }

    /**
     * 承認者選択(UserPicker)など、一般社員も含め誰でも使える軽量な検索専用エンドポイント。
     * 入社日・退社日・雇用区分・ロールのような管理者向けの機微な項目は返さない
     * (それらが必要な一覧・詳細は `index`/`show` を使い、user.view Permissionで保護する)。
     */
    #[OA\Get(
        path: '/users/search',
        operationId: 'users.search',
        summary: '氏名・メールアドレスでユーザーを検索する(承認者選択等の軽量な用途向け)',
        tags: ['ユーザー'],
        parameters: [
            new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'external_unlinked', in: 'query', required: false, description: 'Microsoft未連携のユーザーに絞り込む(1=はい、0=いいえ)', schema: new OA\Schema(type: 'integer', enum: [0, 1])),
            new OA\Parameter(name: 'external_hr', in: 'query', required: false, description: '外部HR管理のユーザーに絞り込む(1=はい、0=いいえ)', schema: new OA\Schema(type: 'integer', enum: [0, 1])),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100)),
            new OA\Parameter(name: 'permission', in: 'query', required: false, description: '指定した場合、globalスコープで当該Permissionを保有するユーザーのみに絞り込む(承認者選択の絞り込み等)', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'ids[]', in: 'query', required: false, description: '指定した場合、そのIDのユーザーのみに絞り込む(存在しないIDは無視する)。q・permissionと併用するとAND。per_page未指定時は指定件数(1ページで全件)', schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'string', format: 'uuid'), maxItems: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful response'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function search(Request $request, EffectiveAccessResolver $resolver): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'permission' => ['sometimes', 'string', 'max:255'],
            'ids' => ['sometimes', 'array', 'max:100'],
            'ids.*' => ['uuid'],
        ]);

        $ids = $validated['ids'] ?? null;
        // ids指定時は既定でその件数を1ページに収める(UserPickerが名前を取得するため)。
        $perPage = $validated['per_page'] ?? ($ids !== null ? max(1, count($ids)) : 50);

        $users = User::query()
            ->when($request->string('q')->toString(), fn ($query, $q) => $query->where(function ($sub) use ($q) {
                $sub->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%");
            }))
            ->when($ids !== null, fn ($query) => $query->whereIn('id', $ids))
            ->when($validated['permission'] ?? null, fn ($query, $permission) => $query->whereIn(
                'id',
                $resolver->userIdsWithGlobalPermission($permission),
            ))
            ->orderBy('name')
            ->paginate($perPage);

        return UserSearchResource::collection($users);
    }

    #[OA\Get(
        path: '/users/{user}',
        operationId: 'users.show',
        summary: 'ユーザー詳細を取得する',
        tags: ['ユーザー'],
        parameters: [
            new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful response'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function show(User $user, EffectiveAccessResolver $resolver): UserResource
    {
        $user->load(['externalIdentities', 'memberships.group.type', 'roleAssignments.role.permissions', 'featureSuspensions.feature']);
        $user->setAttribute('effective_features', $resolver->features($user)->all());
        $user->setAttribute('effective_permissions', $resolver->permissions($user)->all());
        $user->setAttribute('effective_access_explanation', $resolver->explain($user));
        $changeSets = DB::table('membership_change_sets')
            ->where('user_id', $user->id)
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get();
        $changeItems = DB::table('membership_change_items')
            ->whereIn('change_set_id', $changeSets->pluck('id'))
            ->get()
            ->groupBy('change_set_id');
        $user->setAttribute('membership_change_sets', $changeSets->map(
            fn ($set) => (array) $set + ['items' => $changeItems->get($set->id, collect())->values()],
        ));
        $user->setAttribute('field_authorities', DB::table('field_authorities')->orderBy('field_key')->get());

        return new UserResource($user);
    }

    /**
     * 入社日を設定する (docs/09-usecases-paid-leave.md UC-P002)。
     */
    #[OA\Put(
        path: '/users/{user}/hire-date',
        operationId: 'users.updateHireDate',
        summary: '入社日を設定する',
        tags: ['ユーザー'],
        parameters: [
            new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['hire_date'],
                properties: [
                    new OA\Property(property: 'hire_date', type: 'string', format: 'date'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Successful response'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function updateHireDate(Request $request, User $user, CommandBus $commandBus): UserResource
    {
        $data = $request->validate(['hire_date' => ['required', 'date']]);

        $commandBus->dispatch(new SetUserHireDate(
            userId: $user->id,
            hireDate: $data['hire_date'],
            changedByUserId: $request->user()->id,
        ));

        return new UserResource($user->refresh());
    }

    #[OA\Put(
        path: '/users/{user}/termination-date',
        operationId: 'users.updateTerminationDate',
        summary: '退社日を設定する',
        tags: ['ユーザー'],
        parameters: [new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['termination_date'], properties: [new OA\Property(property: 'termination_date', type: 'string', format: 'date', nullable: true)])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function updateTerminationDate(Request $request, User $user, CommandBus $commandBus): UserResource
    {
        $data = $request->validate(['termination_date' => ['nullable', 'date']]);

        $commandBus->dispatch(new SetUserTerminationDate(
            userId: $user->id,
            terminationDate: $data['termination_date'],
            changedByUserId: $request->user()->id,
        ));

        return new UserResource($user->refresh());
    }

    #[OA\Put(
        path: '/users/{user}/usage-start-date',
        operationId: 'users.updateUsageStartDate',
        summary: '利用開始日を設定する',
        tags: ['ユーザー'],
        parameters: [
            new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['usage_start_date'],
                properties: [
                    new OA\Property(property: 'usage_start_date', type: 'string', format: 'date'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Successful response'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function updateUsageStartDate(Request $request, User $user, CommandBus $commandBus): UserResource
    {
        $data = $request->validate(['usage_start_date' => ['required', 'date']]);

        $commandBus->dispatch(new SetUserUsageStartDate(
            userId: $user->id,
            usageStartDate: $data['usage_start_date'],
            changedByUserId: $request->user()->id,
        ));

        return new UserResource($user->refresh());
    }

    /**
     * 有給の自動付与をユーザーごとに有効/無効化する
     * (docs/changesets/20260904-paid-leave-auto-grant-per-user-toggle/spec.md)。
     */
    #[OA\Put(
        path: '/users/{user}/paid-leave-auto-grant-enabled',
        operationId: 'users.updatePaidLeaveAutoGrantEnabled',
        summary: '有給の自動付与の有効/無効を設定する',
        tags: ['ユーザー'],
        parameters: [new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['enabled'], properties: [new OA\Property(property: 'enabled', type: 'boolean')])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function updatePaidLeaveAutoGrantEnabled(Request $request, User $user, CommandBus $commandBus): UserResource
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        $commandBus->dispatch(new SetPaidLeaveAutoGrantEnabled(
            userId: $user->id,
            enabled: $data['enabled'],
            changedByUserId: $request->user()->id,
        ));

        return new UserResource($user->refresh());
    }

    /**
     * 特別休暇の自動付与をユーザーごとに有効/無効化する
     * (docs/changesets/20260904-paid-leave-auto-grant-per-user-toggle/spec.md)。
     */
    #[OA\Put(
        path: '/users/{user}/special-leave-auto-grant-enabled',
        operationId: 'users.updateSpecialLeaveAutoGrantEnabled',
        summary: '特別休暇の自動付与の有効/無効を設定する',
        tags: ['ユーザー'],
        parameters: [new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['enabled'], properties: [new OA\Property(property: 'enabled', type: 'boolean')])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function updateSpecialLeaveAutoGrantEnabled(Request $request, User $user, CommandBus $commandBus): UserResource
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        $commandBus->dispatch(new SetSpecialLeaveAutoGrantEnabled(
            userId: $user->id,
            enabled: $data['enabled'],
            changedByUserId: $request->user()->id,
        ));

        return new UserResource($user->refresh());
    }
}
