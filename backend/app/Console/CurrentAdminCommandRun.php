<?php

namespace App\Console;

use App\Models\AdminCommandRun;

/**
 * `admin-commands:run-pending`が実行中の記録を、実行されるコマンドへ渡すための入れ物。
 * 実行役がコンテナに登録し(`app()->instance(...)`)、コマンドは`CurrentAdminCommandRun::get()`で読む
 * (`Artisan::call`ではIDを渡せず、隠しオプションは運用コマンドの画面に入力欄として出てしまうため)。
 */
final class CurrentAdminCommandRun
{
    public function __construct(public readonly AdminCommandRun $run) {}

    /** 実行役から実行されているときの記録(CLIから直接実行されたときはnull)。 */
    public static function get(): ?AdminCommandRun
    {
        return app()->bound(self::class) ? app(self::class)->run : null;
    }
}
