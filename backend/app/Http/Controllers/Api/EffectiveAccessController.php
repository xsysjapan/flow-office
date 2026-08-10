<?php

namespace App\Http\Controllers\Api;

use App\Domain\AccessControl\Services\EffectiveAccessResolver;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EffectiveAccessController extends Controller
{
    public function __invoke(Request $request, EffectiveAccessResolver $resolver): JsonResponse
    {
        return response()->json([
            'features' => $resolver->features($request->user()),
            'permissions' => $resolver->permissions($request->user()),
            'global_permissions' => $resolver->permissions($request->user())->filter(fn (string $permission) => $resolver->hasGlobalPermission($request->user(), $permission))->values(),
            'explanation' => $resolver->explain($request->user()),
        ]);
    }
}
