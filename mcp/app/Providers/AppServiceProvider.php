<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Caddy/本番Apacheがサブパス(/flow-office/mcp)を剥がしてこのアプリへ転送するため、
        // リクエストのroot URLにはサブパスの情報が含まれない。route()/redirect()->route()が
        // APP_URL(サブパス込み)を起点にURLを生成するよう強制する。
        if ($url = config('app.url')) {
            URL::forceRootUrl($url);
        }
    }
}
