<?php

use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Http\Middleware\EnsureFullAccessOrExplicitAbility;
use App\Http\Middleware\EnsureUserHasRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => EnsureUserHasRole::class,
            // 端末(devices)・認証キー発行トークン等、限定abilityのSanctumトークン用。
            // docs/23-usecases-devices.md UC-D002参照。
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);

        // 限定abilityのトークンが、それ用に用意された経路(ability:/abilities:が
        // 明示的に付与されたルート)以外の一般APIを操作できないようにするグローバルな
        // 安全策(docs/23-usecases-devices.md UC-D002「重要な考慮事項」)。
        $middleware->api(append: [EnsureFullAccessOrExplicitAbility::class]);

        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('api/*') ? null : route('login'),
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // CommandHandlerが検知した業務ルール違反はクライアント起因のエラーとして422で返す。
        $exceptions->render(function (DomainRuleException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        });
    })->create();
