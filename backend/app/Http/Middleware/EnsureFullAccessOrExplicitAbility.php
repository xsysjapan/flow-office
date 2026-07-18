<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 限定abilityしか持たないSanctumトークン(端末トークン`recorder:punch`、個人端末・認証キー
 * 発行の`punch:self`等)が、それ用に用意された経路以外の一般APIを操作できてしまわないよう
 * 遮断するグローバルな安全策(docs/23-usecases-devices.md UC-D002「重要な考慮事項」)。
 *
 * 通常のSSOログイン由来のユーザートークンは`createToken('api')`でability`['*']`を
 * 持つため、このミドルウェアは既存の挙動に影響しない。ability`['*']`を持たない
 * トークンの場合のみ、現在のルートに`ability:`/`abilities:`ミドルウェアが明示的に
 * 付与されているかを確認し、無ければ403にする(明示的にオプトインした経路以外は
 * 一律拒否するデフォルト拒否方式)。
 */
class EnsureFullAccessOrExplicitAbility
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if ($token !== null && method_exists($token, 'can') && ! $token->can('*')) {
            $middlewareNames = $request->route()?->gatherMiddleware() ?? [];
            $optedIn = collect($middlewareNames)->contains(
                fn (string $middleware) => str_starts_with($middleware, 'ability:') || str_starts_with($middleware, 'abilities:')
            );

            abort_unless($optedIn, 403, 'このトークンではこの操作はできません。');
        }

        return $next($request);
    }
}
