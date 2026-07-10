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

// UC-P002: 有給を毎日自動付与する(継続勤務期間の記念日にのみ実際に付与される)。
Schedule::command('paid-leave:grant-scheduled')
    ->dailyAt('02:00')
    ->withoutOverlapping();

// UC-P005: 有給消滅警告を毎日通知する。
Schedule::command('paid-leave:warn-expiring')
    ->dailyAt('02:10')
    ->withoutOverlapping();

// UC-P006: 年5日取得義務警告を毎日通知する。
Schedule::command('paid-leave:warn-five-day-obligation')
    ->dailyAt('02:20')
    ->withoutOverlapping();
