<?php

namespace App\Http\Middleware;

use App\Domain\AccessControl\Services\EffectiveAccessResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureEffectivePermission
{
    public function __construct(private EffectiveAccessResolver $resolver) {}

    public function handle(Request $request, Closure $next, string $permission, string $scopeMode = 'resource'): Response
    {
        $user = $request->user();
        if (! DB::table('permissions')->where('code', $permission)->exists()) {
            return $next($request);
        }
        $resourceGroupId = $this->routeId($request->route('group') ?? $request->route('scopeGroup'));
        $resourceUserId = $this->routeId($request->route('user') ?? $request->route('userId'));
        $allowed = $user && match ($scopeMode) {
            'any' => $this->resolver->permissions($user)->contains($permission),
            'self' => $this->resolver->hasPermission($user, $permission, $resourceGroupId, $user->id),
            default => $this->resolver->hasPermission($user, $permission, $resourceGroupId, $resourceUserId),
        };
        abort_unless($allowed, 403);

        return $next($request);
    }

    private function routeId(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_object($value) && method_exists($value, 'getRouteKey')) {
            return (string) $value->getRouteKey();
        }

        return null;
    }
}
