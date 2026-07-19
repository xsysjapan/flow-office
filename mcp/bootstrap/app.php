<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // apiPrefixを空にしているため(/mcp, /oauth/registerをルート直下に置くため)、
        // パスの'api/*'一致では判定できない。DCR・MCPエンドポイントは常にJSON、
        // /oauth/authorize・/link はブラウザ向けなので通常のリダイレクト挙動のままにする。
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->expectsJson()
                || $request->is('oauth/register')
                || $request->is('mcp'),
        );
    })->create();
