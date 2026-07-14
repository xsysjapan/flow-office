<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;

/**
 * UC-M001: 権限を設定する。割り当て可能なロールの一覧を返す(マスタ参照のみ)。
 */
#[OA\Tag(name: 'ユーザー', description: 'ユーザー・権限管理')]
class RoleController extends Controller
{
    #[OA\Get(
        path: '/roles',
        operationId: 'roles.index',
        summary: 'ロール一覧を取得する',
        tags: ['ユーザー'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function index(): AnonymousResourceCollection
    {
        return RoleResource::collection(Role::query()->orderBy('id')->get());
    }
}
