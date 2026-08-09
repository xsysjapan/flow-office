<?php

namespace App\Http\Middleware;

use App\Domain\AccessControl\Services\EffectiveAccessResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureEffectiveFeature
{
    public function __construct(private EffectiveAccessResolver $resolver) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $user = $request->user();
        if (config('access_control.allow_unconfigured_catalog', false) && ! DB::table('features')->where('code', $feature)->exists()) {
            return $next($request);
        }
        abort_unless(DB::table('features')->where('code', $feature)->exists(), 500, "Undefined feature: {$feature}");
        abort_unless($user && $this->resolver->hasFeature($user, $feature), 403);

        return $next($request);
    }
}
