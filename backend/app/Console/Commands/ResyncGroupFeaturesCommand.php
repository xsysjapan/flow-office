<?php

namespace App\Console\Commands;

use App\Console\Attributes\AdminExecutable;
use App\Domain\AccessControl\Commands\SyncGroupFeatures;
use App\Domain\EventSourcing\CommandBus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Role→Feature自動適用の再同期バッチ。有効なグループごとに`SyncGroupFeatures`を発行し、`role_features` ×
 * 有効な`role_assignments`(subject_type='group')から導かれる集合との差分だけを、グループのFeature割当のイベントとして記録する。
 * `group_feature_assignments`を直接消さない(ReadModelはイベントと常に同期する)。差分が無ければ何も変わらず、いつでも安全に再実行できる。
 */
#[AdminExecutable(label: 'グループFeature割当の再同期')]
class ResyncGroupFeaturesCommand extends Command
{
    protected $signature = 'access-control:resync-group-features';

    protected $description = 'Roleに紐づくFeatureマスタからグループへの有効Feature割当を再同期する';

    public function handle(CommandBus $commandBus): int
    {
        $groupIds = DB::table('groups')->where('status', 'active')->pluck('id');
        foreach ($groupIds as $groupId) {
            $commandBus->dispatch(new SyncGroupFeatures((string) $groupId));
        }

        $this->info(count($groupIds).' グループのFeature割当を再同期しました。');

        return self::SUCCESS;
    }
}
