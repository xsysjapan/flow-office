<?php

namespace App\Services\PaidLeave;

use App\Models\AdminCommandRun;
use App\Models\SystemSetting;
use App\Models\User;
use Cron\CronExpression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * 有給付与予定の出勤率の自動判定(`paid-leave:assess-schedules`)の実行の記録(`admin_command_runs`)の作成・取得・
 * 固まった記録の扱い・次回実行日時の計算
 * (docs/changesets/20261021-paid-leave-fiscal-mass-grant-and-auto-assessment/spec.md 論点6・7)。
 * 運用の基盤(`admin_command_runs`・キャッシュのロック)に依存するので、アプリケーション層に置く。
 * 実行の記録は運用の記録で、業務の状態ではない(イベントにしない)。
 *
 * 二重実行の防止: 記録の作成・取り出しは`Cache::lock(LOCK_NAME)`の中で行い、`queued`/`running`の記録があれば作らない。
 */
class ScheduleAssessmentRunService
{
    public const COMMAND_NAME = 'paid-leave:assess-schedules';

    public const LOCK_NAME = 'paid-leave-assessment-run';

    /** 定期実行のcron式(毎月1日03:00)。`routes/console.php`のスケジュールと次回実行日時の計算で共有する。 */
    public const SCHEDULE_CRON = '0 3 1 * *';

    public const TRIGGER_SCHEDULE = 'schedule';

    public const TRIGGER_MANUAL = 'manual';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    /** `running`のまま、`started_at`からこの時間(分)を超えた記録は失敗とみなす。 */
    public const RUNNING_TIMEOUT_MINUTES = 120;

    /** `queued`のまま、作成からこの時間(分)を超えた記録は失敗とみなす。 */
    public const QUEUED_TIMEOUT_MINUTES = 30;

    public const STALE_MESSAGE = '応答がないため失敗とみなしました';

    /**
     * 手動の記録(`status=queued`、`trigger=manual`)を作る。`queued`/`running`の記録があればnull(409にする)。
     */
    public function createManualRun(User $requester): ?AdminCommandRun
    {
        return $this->withLock(function () use ($requester): ?AdminCommandRun {
            $this->failStaleRuns();

            if ($this->hasActiveRun()) {
                return null;
            }

            return AdminCommandRun::query()->create([
                'command_name' => self::COMMAND_NAME,
                'parameters' => ['trigger' => self::TRIGGER_MANUAL],
                'status' => self::STATUS_QUEUED,
                'requested_by_user_id' => $requester->id,
            ]);
        });
    }

    /**
     * 定期実行の記録(`status=running`、`trigger`、依頼者なし)を作る。`queued`/`running`の記録があればnull(記録を作らずに終える)。
     */
    public function startRun(string $trigger): ?AdminCommandRun
    {
        return $this->withLock(function () use ($trigger): ?AdminCommandRun {
            $this->failStaleRuns();

            if ($this->hasActiveRun()) {
                return null;
            }

            return AdminCommandRun::query()->create([
                'command_name' => self::COMMAND_NAME,
                'parameters' => ['trigger' => $trigger],
                'status' => self::STATUS_RUNNING,
                'started_at' => now(),
            ]);
        });
    }

    /**
     * 待っている手動の記録を1件(古い順)取り出して`running`にする。`running`の記録があれば、または待っている記録が無ければnull。
     */
    public function claimPendingRun(): ?AdminCommandRun
    {
        return $this->withLock(function (): ?AdminCommandRun {
            $this->failStaleRuns();

            if ($this->baseQuery()->where('status', self::STATUS_RUNNING)->exists()) {
                return null;
            }

            $run = $this->baseQuery()
                ->where('status', self::STATUS_QUEUED)
                ->orderBy('created_at')
                ->orderBy('id')
                ->first();

            if ($run === null) {
                return null;
            }

            $run->update(['status' => self::STATUS_RUNNING, 'started_at' => now()]);

            return $run;
        });
    }

    /**
     * 固まった記録を失敗にする(`running`のまま`started_at`から2時間超、`queued`のまま作成から30分超)。
     *
     * @return int 失敗にした件数
     */
    public function failStaleRuns(): int
    {
        $failed = 0;

        $stale = $this->baseQuery()
            ->where(function ($query): void {
                $query->where(fn ($q) => $q->where('status', self::STATUS_RUNNING)
                    ->where('started_at', '<', now()->subMinutes(self::RUNNING_TIMEOUT_MINUTES)))
                    ->orWhere(fn ($q) => $q->where('status', self::STATUS_QUEUED)
                        ->where('created_at', '<', now()->subMinutes(self::QUEUED_TIMEOUT_MINUTES)));
            })
            ->get();

        foreach ($stale as $run) {
            $run->update([
                'status' => self::STATUS_FAILED,
                'finished_at' => now(),
                'error_message' => self::STALE_MESSAGE,
            ]);
            $failed++;
        }

        return $failed;
    }

    /**
     * 固まった記録を片付けた上で、最新の記録(無ければnull)。
     */
    public function latestRun(): ?AdminCommandRun
    {
        $this->failStaleRuns();

        return $this->baseQuery()
            ->with('requestedByUser:id,name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * 次回の定期実行日時(既定のタイムゾーンの壁時計時刻を持つCarbon)。`SCHEDULE_CRON`と既定のタイムゾーンから計算する。
     */
    public function nextRunAt(?Carbon $from = null): Carbon
    {
        $timezone = self::defaultTimezone();
        $from = ($from ?? now())->copy()->setTimezone($timezone);

        $next = (new CronExpression(self::SCHEDULE_CRON))->getNextRunDate($from->toDateTime(), 0, false, $timezone);

        return Carbon::instance($next)->setTimezone($timezone);
    }

    /**
     * 定期実行のタイムゾーン(`system_settings.default_timezone`)。`routes/console.php`はどのartisanコマンドでも読み込まれ、
     * テーブルがまだ無い環境(migrate前)でも壊さないように、取得できなければ`Asia/Tokyo`を返す。
     * `SystemSetting::current()`は行が無いと作ってしまうため使わず、読むだけにする(routes/console.phpは起動のたびに
     * 読み込まれるので、ここで行を作ると副作用になる)。
     */
    public static function defaultTimezone(): string
    {
        try {
            return SystemSetting::query()->value('default_timezone') ?: 'Asia/Tokyo';
        } catch (Throwable) {
            return 'Asia/Tokyo';
        }
    }

    public function hasActiveRun(): bool
    {
        return $this->baseQuery()->whereIn('status', [self::STATUS_QUEUED, self::STATUS_RUNNING])->exists();
    }

    /**
     * @return Builder<AdminCommandRun>
     */
    private function baseQuery()
    {
        return AdminCommandRun::query()->where('command_name', self::COMMAND_NAME);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withLock(callable $callback): mixed
    {
        return Cache::lock(self::LOCK_NAME, 30)->block(10, $callback);
    }
}
