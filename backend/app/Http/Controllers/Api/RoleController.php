<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * UC-M001: 権限を設定する。割り当て可能なロールの一覧を返す(マスタ参照のみ)。
 */
class RoleController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return RoleResource::collection(Role::query()->orderBy('id')->get());
    }
}
