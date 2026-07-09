<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * UC-M001 権限設定に基づき、指定ロールのいずれかを持つユーザーのみ通す。
 * ルート定義例: ->middleware('role:admin,hr_staff')
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roleCodes): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->loadMissing('roles')->roles->pluck('code')->intersect($roleCodes)->count()) {
            abort(403, 'この操作を行う権限がありません。');
        }

        return $next($request);
    }
}
