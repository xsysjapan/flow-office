<?php

namespace App\Console\Commands;

use App\Services\AdminCommand\AdminCommandRunner;
use Illuminate\Console\Command;
use Throwable;

/**
 * 運用コマンドの画面から依頼された記録(`admin_command_runs`の`queued`)を、古い順に1件ずつ実行する実行役
 * (docs/changesets/20261025-read-model-rebuild-admin-command/spec.md 論点1)。
 * `routes/console.php`が毎分・`runInBackground()`・`withoutOverlapping(240)`で起動する(キューのワーカーの60秒の打ち切りを受けない)。
 * 起動時に残っている`running`の記録は前のプロセスの取り残しとして失敗にする(実行役は同時に1本しか動かない)。
 */
class RunPendingAdminCommandsCommand extends Command
{
    protected $signature = 'admin-commands:run-pending';

    protected $description = '運用コマンドの画面から依頼された待機中の記録を、古い順にすべて実行する';

    public function handle(AdminCommandRunner $runner): int
    {
        $stale = $runner->failStaleRunningRuns();
        if ($stale > 0) {
            $this->warn("取り残しの実行中の記録{$stale}件を失敗にしました。");
        }

        $executed = [];
        while (($run = $runner->nextQueuedRun()) !== null) {
            if (isset($executed[$run->id])) {
                break;
            }
            $executed[$run->id] = true;

            try {
                $runner->execute($run);
            } catch (Throwable $e) {
                report($e);
            }
            $this->line("{$run->command_name}: ".($run->fresh()?->status ?? '-'));
        }

        return self::SUCCESS;
    }
}
