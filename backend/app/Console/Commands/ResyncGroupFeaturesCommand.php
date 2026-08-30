<?php

namespace App\Console\Commands;

use App\Domain\AccessControl\Services\GroupFeatureSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Role→Feature自動適用の再構築バッチ。`group_feature_assignments` を一旦全クリアし、
 * `role_features` × 有効な `role_assignments`(subject_type='group')から再構築する。
 * 冪等コマンドであり、いつでも安全に再実行できる。
 */
class ResyncGroupFeaturesCommand extends Command
{
    protected $signature = 'access-control:resync-group-features';

    protected $description = 'Roleに紐づくFeatureマスタからグループへの有効Feature割当を再構築する';

    public function handle(GroupFeatureSyncService $groupFeatureSync): int
    {
        DB::table('group_feature_assignments')->delete();

        $groupIds = DB::table('groups')->where('status', 'active')->pluck('id');
        foreach ($groupIds as $groupId) {
            $groupFeatureSync->syncGroup((string) $groupId);
        }

        $this->info(count($groupIds).' グループのFeature割当を再構築しました。');

        return self::SUCCESS;
    }
}
