<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Models\DeviceStatus;
use Illuminate\Console\Command;

/**
 * docs/23-usecases-devices.md「端末管理画面(UI)」の「最終通信確認」を補助する運用強化コマンド。
 * 一定期間ハートビート(POST /devices/heartbeat)が届いていない有効な端末を検出しログに残す。
 * 常駐workerを前提としないため(docs/02-tech-stack.md)、cronから毎日実行する想定。
 *
 * Teams通知への連携は今回のスコープ外とし、ログ出力(および終了コード)のみで報告する。
 */
class DeviceHealthCheckCommand extends Command
{
    protected $signature = 'devices:health-check {--stale-after-hours=48}';

    protected $description = '一定時間ハートビートが無い有効な端末を検出する';

    public function handle(): int
    {
        $staleAfterHours = (int) $this->option('stale-after-hours');

        $staleDevices = Device::query()
            ->where('status', DeviceStatus::ACTIVE)
            ->where(function ($query) use ($staleAfterHours) {
                $query->whereNull('last_seen_at')
                    ->orWhere('last_seen_at', '<', now()->subHours($staleAfterHours));
            })
            ->get();

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
