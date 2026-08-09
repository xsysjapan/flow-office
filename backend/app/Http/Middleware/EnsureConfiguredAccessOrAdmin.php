<?php

namespace App\Http\Middleware;

use App\Models\Role;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Feature / Permissionカタログ移行済みなら後続の実効アクセス認可へ委ね、
 * 未移行の互換モードだけ従来のadmin Roleを要求する。
 */
class EnsureConfiguredAccessOrAdmin
{
    public function handle(Request $request, Closure $next, string $feature, ?string $permission = null): Response
    {
        $featureConfigured = DB::table('features')->where('code', $feature)->exists();
        $permissionConfigured = $permission === null || DB::table('permissions')->where('code', $permission)->exists();

        if (! $featureConfigured || ! $permissionConfigured) {
            abort_unless($request->user()?->hasRole(Role::ADMIN), 403);
        }

        return $next($request);
    }
}
