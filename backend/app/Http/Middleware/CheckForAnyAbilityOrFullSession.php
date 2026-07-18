<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Laravel\Sanctum\Exceptions\MissingAbilityException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sanctum標準の`CheckForAnyAbility`は、解決可能な`currentAccessToken()`が無い場合
 * (Bearerトークンを経由しない通常のログインセッション、あるいはテストの`actingAs()`)に
 * 常に401を返してしまう。flow-officeでは「ability`*`を持つ通常の人間向けトークン」と
 * 「トークンを介さない認証」を同列に「フルアクセス」として扱いたいため
 * (`EnsureFullAccessOrExplicitAbility`と同じ方針)、トークンが解決できない場合は
 * このミドルウェアでは拒否せず素通りさせる、独自のability検証に置き換える
 * (docs/25-usecases-integrations-mcp.md UC-I002の実装で、既存エンドポイントに
 * `ability:`タグを後付けしてもテスト・既存の人間向けセッションを壊さないため)。
 */
class CheckForAnyAbilityOrFullSession
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
            if ($token->can($ability)) {
                return $next($request);
            }
        }

        throw new MissingAbilityException($abilities);
    }
}
