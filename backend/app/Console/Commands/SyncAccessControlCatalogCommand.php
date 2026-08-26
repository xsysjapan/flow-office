<?php

namespace App\Console\Commands;

use App\Domain\AccessControl\Services\SyncAccessControlCatalog;
use Illuminate\Console\Command;

/**
 * `AccessControlCatalog`に追加されたFeature・PermissionをDBへ反映する。デプロイのたびに
 * `deploy/scripts/activate-release.sh`から自動実行し、コードにFeature・Permissionを
 * 追加するだけで管理画面(アクセス管理)の一覧に自動的に反映されるようにする
 * (本番ではseeder全体は実行しないため、`migrate --force`と同様デプロイ時に必ず
 * 実行される経路が必要)。
 */
class SyncAccessControlCatalogCommand extends Command
{
    protected $signature = 'access-control:sync-catalog';

    protected $description = 'AccessControlCatalogに定義されたFeature・PermissionをDBへ反映する(追加専用・既存の割当は変更しない)';

    public function handle(SyncAccessControlCatalog $sync): int
    {
        $sync->sync();

        $this->info('Feature・Permissionを同期しました。');

        return self::SUCCESS;
    }
}
