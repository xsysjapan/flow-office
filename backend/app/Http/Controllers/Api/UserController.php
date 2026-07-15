<?php

namespace App\Http\Controllers\Api;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\User\Commands\AssignUserRoles;
use App\Domain\User\Commands\SetUserHireDate;
use App\Domain\User\Commands\SetUserTerminationDate;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

/**
 * UC-M001: 権限を設定する / ユーザー一覧管理。
 */
#[OA\Tag(name: 'ユーザー', description: 'ユーザー・権限管理')]
class UserController extends Controller
{
    #[OA\Get(
        path: '/users',
        operationId: 'users.index',
        summary: 'ユーザー一覧を取得する',
        tags: ['ユーザー'],
        parameters: [
            new OA\Parameter(name: 'q', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful response'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
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
        operationId: 'users.show',
        summary: 'ユーザー詳細を取得する',
        tags: ['ユーザー'],
        parameters: [
            new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful response'),
            new OA\Response(response: 401, description: 'Unauthenticated'),
        ],
    )]
    public function show(User $user): UserResource
    {
        return new UserResource($user->load('roles'));
    }

    #[OA\Put(
        path: '/users/{user}/roles',
        operationId: 'users.updateRoles',
        summary: 'ユーザーのロールを更新する',
        tags: ['ユーザー'],
        parameters: [
            new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
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
            new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
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

        return new UserResource($user->refresh()->load('roles'));
    }

    #[OA\Put(
        path: '/users/{user}/termination-date',
        operationId: 'users.updateTerminationDate',
        summary: '退社日を設定する',
        tags: ['ユーザー'],
        parameters: [new OA\Parameter(name: 'user', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
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

        return new UserResource($user->refresh()->load('roles'));
    }
}
