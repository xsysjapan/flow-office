<?php

namespace App\Http\Controllers\Api;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\User\Commands\AssignUserRoles;
use App\Domain\User\Commands\SetUserHireDate;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

/**
 * UC-M001: 権限を設定する / ユーザー一覧管理。
 */
#[OA\Tag(name: 'Users', description: 'ユーザー・権限管理 (docs/15-usecases-admin.md UC-M001)')]
class UserController extends Controller
{
    #[OA\Get(
        path: '/users',
        summary: 'ユーザー一覧を取得する',
        tags: ['Users'],
        parameters: [
            new OA\Parameter(name: 'q', description: '氏名・メールアドレスの部分一致検索', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'ユーザー一覧(ページネーション付き)'),
        ],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $users = User::query()
            ->with('roles')
            ->when($request->string('q')->toString(), fn ($query, $q) => $query->where(function ($sub) use ($q) {
                $sub->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%");
            }))
            ->orderBy('name')
            ->paginate(50);

        return UserResource::collection($users);
    }

    #[OA\Get(
        path: '/users/{user}',
        summary: 'ユーザー詳細を取得する',
        tags: ['Users'],
        parameters: [
            new OA\Parameter(name: 'user', description: 'ユーザーID', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'ユーザー詳細'),
            new OA\Response(response: 404, description: 'ユーザーが存在しない'),
        ],
    )]
    public function show(User $user): UserResource
    {
        return new UserResource($user->load('roles'));
    }

    #[OA\Put(
        path: '/users/{user}/roles',
        summary: 'ユーザーの権限を設定する',
        tags: ['Users'],
        parameters: [
            new OA\Parameter(name: 'user', description: 'ユーザーID', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
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
            new OA\Response(response: 200, description: '更新後のユーザー詳細'),
            new OA\Response(response: 403, description: 'admin/hr_staff権限が必要'),
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
        summary: 'ユーザーの入社日を設定する',
        description: '有給付与日数の起算日として使われる (docs/09-usecases-paid-leave.md UC-P002)。',
        tags: ['Users'],
        parameters: [
            new OA\Parameter(name: 'user', description: 'ユーザーID', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
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
            new OA\Response(response: 200, description: '更新後のユーザー詳細'),
            new OA\Response(response: 403, description: 'admin/hr_staff権限が必要'),
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

        return new UserResource($user->refresh()->load('roles'));
    }
}
