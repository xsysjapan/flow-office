<?php

namespace App\Domain\Device\Handlers;

use App\Domain\Device\Commands\WarnStaleDevices;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Jobs\SendTeamsNotificationJob;
use App\Models\Device;
use App\Models\DeviceStatus;
use Illuminate\Database\Eloquent\Collection;

/**
 * docs/23-usecases-devices.md「端末管理画面」の「最終通信確認」: 一定時間ハートビートが
 * 無い有効な端末をTeamsへ警告する。cronから毎日実行する想定(add-teams-notification参照)。
 *
 * @implements CommandHandler<WarnStaleDevices>
 */
class WarnStaleDevicesHandler implements CommandHandler
{
    /**
     * @return Collection<int, Device> 警告対象の端末一覧
     */
    public function handle(Command $command): Collection
    {
        assert($command instanceof WarnStaleDevices);

        $staleDevices = Device::query()
            ->where('status', DeviceStatus::ACTIVE)
            ->where(function ($query) use ($command) {
                $query->whereNull('last_seen_at')
                    ->orWhere('last_seen_at', '<', now()->subHours($command->staleAfterHours));
            })
            ->get();

        if ($staleDevices->isEmpty()) {
            return $staleDevices;
        }

        $names = $staleDevices->pluck('name')->implode('、');

        SendTeamsNotificationJob::enqueue(
            title: '端末の疎通が途絶えています',
            summary: "{$staleDevices->count()}件の端末が{$command->staleAfterHours}時間以上疎通していません: {$names}",
            detailUrl: null,
        );

        return $staleDevices;
    }
}
