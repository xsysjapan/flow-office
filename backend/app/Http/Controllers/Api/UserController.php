<?php

namespace App\Http\Controllers\Api;

use App\Domain\AccessControl\Services\EffectiveAccessResolver;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\UserManagement\Commands\AssignUserRoles;
use App\Domain\UserManagement\Commands\CreateUser;
use App\Domain\UserManagement\Commands\SetUserHireDate;
use App\Domain\UserManagement\Commands\SetUserTerminationDate;
use App\Domain\UserManagement\Commands\SetUserUsageStartDate;
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
        ]);
        app(FieldAuthorityService::class)->assertLocallyEditable(array_keys($attributes));
        $id = (string) Str::uuid();
        $user = $commandBus->dispatch(new CreateUser($id, [
            ...$attributes,
            'employment_status' => $attributes['employment_status'] ?? 'active',
            'account_status' => $attributes['account_status'] ?? 'active',
        ], $request->user()->id));

        return (new UserResource($user->load(['roles', 'externalIdentities', 'memberships.group.type'])))
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

        return new UserResource($user->refresh()->load(['roles', 'externalIdentities', 'memberships.group.type']));
    }

    #[OA\Get(
        path: '/users',
        operationId: 'users.index',
        summary: 'ユーザー一覧を取得する',
        tags: ['ユーザー'],
        parameters: [
            new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
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
            ->with(['roles', 'externalIdentities', 'memberships.group.type'])
            ->when($request->string('q')->toString(), fn ($query, $q) => $query->where(function ($sub) use ($q) {
                $sub->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%")->orWhere('employee_number', 'like', "%{$q}%");
            }))
            ->when($validated['group_id'] ?? null, fn ($query, $groupId) => $query->whereHas('memberships', fn ($q) => $q->where('group_id', $groupId)))
            ->when($validated['group_type_id'] ?? null, fn ($query, $typeId) => $query->whereHas('memberships.group', fn ($q) => $q->where('group_type_id', $typeId)))
            ->when(($validated['external_unlinked'] ?? false), fn ($query) => $query->whereDoesntHave('externalIdentities', fn ($q) => $q->where('provider', 'MICROSOFT_ENTRA')->where('status', 'active')))
            ->when(($validated['external_hr'] ?? false), fn ($query) => $query->whereHas('externalIdentities', fn ($q) => $q->where('provider', 'EXTERNAL_HR')->where('status', 'active')))
            ->when($validated['account_status'] ?? null, fn ($query, $status) => $query->where('account_status', $status));

        if (! $resolver->hasGlobalPermission($request->user(), 'user.manage')) {
            $permittedGroupIds = $resolver->permittedGroupIds($request->user(), 'user.manage');
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
     * (それらが必要な一覧・詳細は `index`/`show` を使い、role:admin,hr_staff で保護する)。
     */
    #[OA\Get(
        path: '/users/search',
        operationId: 'users.search',
        summary: '氏名・メールアドレスでユーザーを検索する(承認者選択等の軽量な用途向け)',
        tags: ['ユーザー'],
        parameters: [
            new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful response'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function search(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $users = User::query()
            ->when($request->string('q')->toString(), fn ($query, $q) => $query->where(function ($sub) use ($q) {
                $sub->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%");
            }))
            ->orderBy('name')
            ->paginate($validated['per_page'] ?? 50);

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
        $user->load(['roles', 'externalIdentities', 'memberships.group.type', 'roleAssignments.role.permissions', 'featureSuspensions.feature']);
        $user->setAttribute('effective_features', $resolver->features($user)->all());
        $user->setAttribute('effective_permissions', $resolver->permissions($user)->all());
        $user->setAttribute('membership_change_sets', DB::table('membership_change_sets')->where('user_id', $user->id)->orderByDesc('effective_at')->get());
        $user->setAttribute('field_authorities', DB::table('field_authorities')->orderBy('field_key')->get());

        return new UserResource($user);
    }

    #[OA\Put(
        path: '/users/{user}/roles',
        operationId: 'users.updateRoles',
        summary: 'ユーザーのロールを更新する',
        tags: ['ユーザー'],
        parameters: [
            new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['role_codes'],
                properties: [
                    new OA\Property(property: 'role_codes', type: 'array', items: new OA\Items(type: 'string')),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Successful response'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function updateRoles(Request $request, User $user, CommandBus $commandBus): UserResource
    {
        $data = $request->validate([
            'role_codes' => ['required', 'array'],
            'role_codes.*' => ['string'],
        ]);

        $commandBus->dispatch(new AssignUserRoles(
            userId: $user->id,
            roleCodes: $data['role_codes'],
            changedByUserId: $request->user()->id,
        ));

        return new UserResource($user->refresh()->load('roles'));
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
        app(FieldAuthorityService::class)->assertLocallyEditable(['hire_date']);
        $data = $request->validate(['hire_date' => ['required', 'date']]);

        $commandBus->dispatch(new SetUserHireDate(
            userId: $user->id,
            hireDate: $data['hire_date'],
            changedByUserId: $request->user()->id,
        ));

        return new UserResource($user->refresh()->load('roles'));
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
        app(FieldAuthorityService::class)->assertLocallyEditable(['termination_date']);
        $data = $request->validate(['termination_date' => ['nullable', 'date']]);

        $commandBus->dispatch(new SetUserTerminationDate(
            userId: $user->id,
            terminationDate: $data['termination_date'],
            changedByUserId: $request->user()->id,
        ));

        return new UserResource($user->refresh()->load('roles'));
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
        app(FieldAuthorityService::class)->assertLocallyEditable(['usage_start_date']);
        $data = $request->validate(['usage_start_date' => ['required', 'date']]);

        $commandBus->dispatch(new SetUserUsageStartDate(
            userId: $user->id,
            usageStartDate: $data['usage_start_date'],
            changedByUserId: $request->user()->id,
        ));

        return new UserResource($user->refresh()->load('roles'));
    }
}
