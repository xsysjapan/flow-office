<?php

namespace App\Services\AdminCommand;

use App\Console\AdminCommandRegistry;
use App\Console\CurrentAdminCommandRun;
use App\Models\AdminCommandRun;
use App\Services\EventSourcing\ReadModelRebuildRunService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * 運用コマンド(`#[AdminExecutable]`)の実行の記録(`admin_command_runs`)の取り出しと実行
 * (docs/changesets/20261025-read-model-rebuild-admin-command/spec.md 論点1)。`admin-commands:run-pending`が使う。
 * 対象は`AdminCommandRegistry`に載っているコマンドの記録だけ(有給付与予定の判定の記録など、別の経路で動くものは触らない)。
 * 実行の記録は運用の記録で、業務の状態ではない(イベントにしない)。
 */
class AdminCommandRunner
{
    /** 排他のロックの期限(分)。作り直しの時間の上限(180分)に余裕を足し、実行役の`withoutOverlapping`と揃える。 */
    public const LOCK_MINUTES = 240;

    public const STALE_MESSAGE = '応答がないため失敗とみなしました';

    public function __construct(
        private readonly AdminCommandRegistry $registry,
        private readonly ReadModelRebuildRunService $rebuildRunService,
    ) {}

    /**
     * 実行役の起動時に残っている`running`の記録は、前のプロセスが落ちた取り残しなので失敗にし、排他のロックを解放する。
     *
     * @return int 失敗にした件数
     */
    public function failStaleRunningRuns(): int
    {
        $failed = 0;
        $runs = AdminCommandRun::query()
            ->whereIn('command_name', $this->commandNames())
            ->where('status', 'running')
            ->get();
        foreach ($runs as $run) {
            $run->update(['status' => 'failed', 'finished_at' => now(), 'error_message' => self::STALE_MESSAGE]);
            Cache::lock(self::lockName($run->command_name))->forceRelease();
            $failed++;
        }

        return $failed;
    }

    /**
     * 待っている記録を1件(古い順)返す。有給付与予定の判定が`queued`/`running`の間は、作り直しの記録を飛ばす(`queued`のまま残す)。
     */
    public function nextQueuedRun(): ?AdminCommandRun
    {
        $query = AdminCommandRun::query()
            ->whereIn('command_name', $this->commandNames())
            ->where('status', 'queued');
        if ($this->rebuildRunService->isAssessmentActive()) {
            $query->where('command_name', '!=', ReadModelRebuildRunService::COMMAND_NAME);
        }

        return $query->orderBy('created_at')->orderBy('id')->first();
    }

    /**
     * 記録を実行し、結果(`succeeded`/`failed`・`exit_code`・`output`・`error_message`)を書く。例外は受け止めて失敗にする。
     */
    public function execute(AdminCommandRun $run): void
    {
        try {
            $metadata = $this->registry->find($run->command_name);
            if ($metadata === null) {
                $this->fail($run, 'このコマンドは公開されていません。');

                return;
            }

            $execute = fn () => $this->call($run, $metadata);
            if ($metadata['without_overlapping']) {
                Cache::lock(self::lockName($run->command_name), self::LOCK_MINUTES * 60)->block(1, $execute);
            } else {
                $execute();
            }
        } catch (LockTimeoutException) {
            $this->fail($run, '同じコマンドが既に実行中です。');
        } catch (Throwable $exception) {
            report($exception);
            $this->fail($run, $exception->getMessage());
        }
    }

    public static function lockName(string $commandName): string
    {
        return 'admin-command:'.$commandName;
    }

    /** @param  array<string, mixed>  $metadata */
    private function call(AdminCommandRun $run, array $metadata): void
    {
        $run->update(['status' => 'running', 'started_at' => now()]);

        $arguments = [];
        $parameterMetadata = collect($metadata['parameters'])->keyBy('name');
        foreach (($run->parameters ?? []) as $name => $value) {
            $arguments[$parameterMetadata[$name]['kind'] === 'option' ? '--'.$name : $name] = $value;
        }

        app()->instance(CurrentAdminCommandRun::class, new CurrentAdminCommandRun($run));
        try {
            $exitCode = Artisan::call($run->command_name, $arguments);
            $output = mb_substr(Artisan::output(), 0, 100000);
        } finally {
            app()->forgetInstance(CurrentAdminCommandRun::class);
        }

        $run->update([
            'status' => $exitCode === 0 ? 'succeeded' : 'failed',
            'finished_at' => now(), 'exit_code' => $exitCode,
            'output' => $output,
        ]);
    }

    private function fail(AdminCommandRun $run, string $message): void
    {
        $run->update(['status' => 'failed', 'finished_at' => now(), 'error_message' => mb_substr($message, 0, 2000)]);
    }

    /** @return list<string> */
    private function commandNames(): array
    {
        return array_keys($this->registry->all());
    }
}
