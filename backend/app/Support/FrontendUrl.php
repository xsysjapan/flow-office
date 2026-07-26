<?php

namespace App\Support;

/**
 * 通知メール等に載せる、フロントエンドSPA上の画面へのリンクを組み立てる
 * (`config('app.frontend_url')`、既存の`AuthController`のSSOコールバックURL組み立てと同じ
 * パターン)。バックエンドAPIのURLではない点に注意。
 */
class FrontendUrl
{
    public static function path(string $path): string
    {
        return rtrim(config('app.frontend_url'), '/').$path;
    }
}
