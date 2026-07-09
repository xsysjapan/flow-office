<?php

namespace App\Http\Controllers\Api;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\User\Commands\AssignUserRoles;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * UC-M001: 権限を設定する / ユーザー一覧管理。
 */
class UserController extends Controller
{
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

    public function show(User $user): UserResource
    {
        return new UserResource($user->load('roles'));
    }

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
}
