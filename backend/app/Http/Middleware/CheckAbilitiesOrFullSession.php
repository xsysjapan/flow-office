<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Laravel\Sanctum\Exceptions\MissingAbilityException;
use Symfony\Component\HttpFoundation\Response;

/**
 * CheckForAnyAbilityOrFullSessionと同じ考え方の「全ability」版
 * (Sanctum標準のCheckAbilitiesの置き換え)。現時点では`abilities:`(複数必須)は
 * 未使用だが、`ability:`(いずれか1つ)と同じ理由で用意しておく。
 */
class CheckAbilitiesOrFullSession
{
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        $user = $request->user();
        if ($user === null) {
            throw new AuthenticationException;
        }

        $token = method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;
        if ($token === null) {
            return $next($request);
        }

        foreach ($abilities as $ability) {
            if (! $token->can($ability)) {
                throw new MissingAbilityException([$ability]);
            }
        }

        return $next($request);
    }
}
