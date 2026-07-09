<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// XSERVERでは常駐プロセスを前提にしない (docs/02-tech-stack.md)。
// crontab には `* * * * * php artisan schedule:run` のみを登録し、
// 実際のジョブ実行はここで定義するスケジュールに委ねる。

// Teams通知など、DBキューに積まれたジョブを1分ごとに捌く。
Schedule::command('queue:work --stop-when-empty --max-time=50')
    ->everyMinute()
    ->withoutOverlapping();

// UC-002: MS365ユーザーを毎日同期する。
Schedule::command('users:sync-ms365')
    ->dailyAt('01:00')
    ->withoutOverlapping();
