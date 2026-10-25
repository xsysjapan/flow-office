<?php

use App\Services\EventSourcing\ReadModelRebuildRunService;
use App\Services\PaidLeave\ScheduleAssessmentRunService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// XSERVERでは常駐プロセスを前提にしない (docs/02-tech-stack.md)。
// crontab には `* * * * * php artisan schedule:run` のみを登録し、
// 実際のジョブ実行はここで定義するスケジュールに委ねる。

// ReadModelの作り直し(`read-models:rebuild`)の実行中は、作り直し以外の全スケジュールを飛ばす
// (実行中の書き込みが、トランザクションのロック待ち・再生との競合で漏れたり二重になったりしないように)。
// 新しく起動されるものだけが止まる。飛ばされた日次バッチは後から追い付かない
// (docs/changesets/20261025-read-model-rebuild-admin-command/spec.md 論点3)。
$skipDuringReadModelRebuild = fn (): bool => app(ReadModelRebuildRunService::class)->isRunning();

// 期限到来済みの所属変更セットを適用する。
Schedule::command('user-management:apply-membership-changes')->everyMinute()->withoutOverlapping()
    ->skip($skipDuringReadModelRebuild);

// メール通知など、DBキューに積まれたジョブを1分ごとに捌く。
Schedule::command('queue:work --stop-when-empty --max-time=50')
    ->everyMinute()
    ->withoutOverlapping()
    ->skip($skipDuringReadModelRebuild);

// UC-002: MS365ユーザーを毎日同期する。
Schedule::command('users:sync-ms365')
    ->dailyAt('01:00')
    ->withoutOverlapping()
    ->skip($skipDuringReadModelRebuild);

// UC-P002: 有給付与Scheduleを毎日、明日から1年先まで再計算する(勤務形態の月替わりなど、
// 再計算のきっかけを翌日以降に取りこぼさないため)。出勤率Assessment(判定)は行わない(判定は下の
// `paid-leave:assess-schedules`)。Grant自体も管理者が付与予定画面で確認した上で一括付与する — 自動確定はしない。
// docs/changesets/20260906-paid-leave-schedule-assessment/spec.md 論点6・10
// (旧`paid-leave:grant-scheduled`を置換)、
// docs/changesets/20261021-paid-leave-schedule-recalc-on-assignment-change/spec.md 論点2・4。
Schedule::command('paid-leave:roll-schedules')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->skip($skipDuringReadModelRebuild);

// UC-P012: 付与予定日が来た「予定」の行の出勤率を、毎月1日03:00(既定のタイムゾーン)に判定する。
// キュー(`queue:work`は既定60秒で打ち切られる)を使わず、スケジューラーから直接動かす。
// cron式は、画面に出す次回実行日時の計算と共有する(ScheduleAssessmentRunService::SCHEDULE_CRON)。
// docs/changesets/20261021-paid-leave-fiscal-mass-grant-and-auto-assessment/spec.md 論点6。
Schedule::command('paid-leave:assess-schedules --trigger=schedule')
    ->cron(ScheduleAssessmentRunService::SCHEDULE_CRON)
    ->timezone(ScheduleAssessmentRunService::defaultTimezone())
    ->withoutOverlapping(180)
    ->runInBackground()
    ->skip($skipDuringReadModelRebuild);

// UC-P012: 画面の「今すぐ判定する」が作った待機中の記録を、1分ごとに1件拾って実行する。
Schedule::command('paid-leave:assess-schedules --pending')
    ->everyMinute()
    ->withoutOverlapping(180)
    ->runInBackground()
    ->skip($skipDuringReadModelRebuild);

// UC-P005: 有給消滅警告を毎日通知する。
Schedule::command('paid-leave:warn-expiring')
    ->dailyAt('02:10')
    ->withoutOverlapping()
    ->skip($skipDuringReadModelRebuild);

// UC-P006: 年5日取得義務警告を毎日通知する。
Schedule::command('paid-leave:warn-five-day-obligation')
    ->dailyAt('02:20')
    ->withoutOverlapping()
    ->skip($skipDuringReadModelRebuild);

// 特別休暇種別ごとの自動付与ルールに基づき特別休暇を毎日自動付与する
// (継続勤務期間の記念日にのみ実際に付与される)。
Schedule::command('special-leave:grant-scheduled')
    ->dailyAt('02:05')
    ->withoutOverlapping()
    ->skip($skipDuringReadModelRebuild);

// UC-N001「勤怠未提出」: 前月分の勤怠がまだ提出されていない社員に毎日警告する。
Schedule::command('attendance:warn-unsubmitted')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->skip($skipDuringReadModelRebuild);

// UC-N001「月次締め前警告」: 前月分の月次勤怠の締め切りが近づいたら毎日警告する。
Schedule::command('attendance:warn-month-close-deadline')
    ->dailyAt('02:40')
    ->withoutOverlapping()
    ->skip($skipDuringReadModelRebuild);

// docs/23-usecases-devices.md「端末管理画面」: 疎通が途絶えている端末を毎日検出する。
Schedule::command('devices:health-check')
    ->dailyAt('03:00')
    ->withoutOverlapping()
    ->skip($skipDuringReadModelRebuild);

// UC-C012手順2: 祝日iCalendarソースを毎日同期する。
Schedule::command('holiday-calendar:sync')
    ->daily()
    ->withoutOverlapping()
    ->skip($skipDuringReadModelRebuild);

// UC-C014: カレンダー年度を定期バッチで生成する。
Schedule::command('calendar:generate-years')
    ->daily()
    ->withoutOverlapping()
    ->skip($skipDuringReadModelRebuild);

// 運用コマンドの画面(`/admin/commands`)から依頼された記録を、1分ごとに古い順にすべて実行する実行役。
// キュー(`queue:work`は既定60秒で打ち切られる)を使わず、スケジューラーから`runInBackground()`で直接動かす。
// 作り直し(`read-models:rebuild`)はこの実行役の中で動くので、作り直しの実行中は新しく起動しない(`skip`)。
// `withoutOverlapping`の期限は作り直しの時間の上限(180分)に余裕を足した240分(プロセスが落ちても24時間止めない)。
// docs/changesets/20261025-read-model-rebuild-admin-command/spec.md 論点1・3。
Schedule::command('admin-commands:run-pending')
    ->everyMinute()
    ->withoutOverlapping(240)
    ->runInBackground()
    ->skip($skipDuringReadModelRebuild);
