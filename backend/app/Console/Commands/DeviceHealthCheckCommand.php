<?php

namespace App\Console\Commands;

use App\Domain\Device\Commands\WarnStaleDevices;
use App\Domain\EventSourcing\CommandBus;
use Illuminate\Console\Command;

/**
 * docs/23-usecases-devices.md「端末管理画面(UI)」の「最終通信確認」を補助する運用強化コマンド。
 * 一定期間ハートビート(POST /devices/heartbeat)が届いていない有効な端末を検出し、
 * ログ出力に加えTeamsへも警告する(add-teams-notificationスキル)。常駐workerを前提と
 * しないため(docs/02-tech-stack.md)、cronから毎日実行する想定。
 */
class DeviceHealthCheckCommand extends Command
{
    protected $signature = 'devices:health-check {--stale-after-hours=48}';

    protected $description = '一定時間ハートビートが無い有効な端末を検出しTeamsへ警告する';

    public function handle(CommandBus $commandBus): int
    {
        $staleAfterHours = (int) $this->option('stale-after-hours');

        $staleDevices = $commandBus->dispatch(new WarnStaleDevices($staleAfterHours));

        if ($staleDevices->isEmpty()) {
            $this->info('疎通が途絶えている端末はありません。');

            return self::SUCCESS;
        }

        foreach ($staleDevices as $device) {
            $lastSeen = $device->last_seen_at?->toIso8601String() ?? '(一度も通信なし)';
            $this->warn("端末#{$device->id}({$device->name})の最終通信: {$lastSeen}");
        }

        $this->warn("{$staleDevices->count()}件の端末が{$staleAfterHours}時間以上疎通していません。");

        return self::SUCCESS;
    }
}
