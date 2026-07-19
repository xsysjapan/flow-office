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
        // ngrok/Cloudflaredのようなリバースプロキシ経由で動作確認する際、X-Forwarded-*
        // ヘッダーを信頼しないとLaravelが生成するURL(route()・fullUrl()等)のスキームが
        // http/ホストが127.0.0.1のままになり、OAuthのリダイレクトが壊れる。開発用途のため
        // 全プロキシを信頼する('*')。本番でXSERVER等の特定プロキシ配下に置く場合は、
        // ここを個別IPやCIDRに絞ること。
        $middleware->trustProxies(at: '*');
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
