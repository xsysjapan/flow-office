<?php

namespace App\Jobs;

use App\Console\AdminCommandRegistry;
use App\Models\AdminCommandRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class RunAdminCommandJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly string $runId) {}

    public function handle(AdminCommandRegistry $registry): void
    {
        $run = AdminCommandRun::findOrFail($this->runId);
        $metadata = $registry->find($run->command_name);
        if ($metadata === null) {
            $run->update(['status' => 'failed', 'finished_at' => now(), 'error_message' => 'このコマンドは公開されていません。']);

            return;
        }

        $execute = function () use ($run, $metadata): void {
            $run->update(['status' => 'running', 'started_at' => now()]);
            try {
                $arguments = [];
                $parameterMetadata = collect($metadata['parameters'])->keyBy('name');
                foreach ($run->parameters as $name => $value) {
                    $arguments[$parameterMetadata[$name]['kind'] === 'option' ? '--'.$name : $name] = $value;
                }
                $exitCode = Artisan::call($run->command_name, $arguments);
                $run->update([
                    'status' => $exitCode === 0 ? 'succeeded' : 'failed',
                    'finished_at' => now(), 'exit_code' => $exitCode,
                    'output' => mb_substr(Artisan::output(), 0, 100000),
                ]);
            } catch (Throwable $exception) {
                $run->update(['status' => 'failed', 'finished_at' => now(), 'error_message' => mb_substr($exception->getMessage(), 0, 2000)]);
                throw $exception;
            }
        };

        try {
            if ($metadata['without_overlapping']) {
                Cache::lock('admin-command:'.$run->command_name, 3600)->block(1, $execute);
            } else {
                $execute();
            }
        } catch (Throwable $exception) {
            if ($run->fresh()?->status === 'queued') {
                $run->update(['status' => 'failed', 'finished_at' => now(), 'error_message' => '同じコマンドが既に実行中です。']);
            }
            throw $exception;
        }
    }
}
