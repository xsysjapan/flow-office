<?php

namespace App\Console\Commands\Legacy;

use App\Domain\LegacyMigration\UuidMap;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * 本番カットオーバー移行(docs/30-legacy-data-migration.md)手順5: `legacy:convert` +
 * `event-sourcing:replay` の後に実行する、role_userピボットのバックフィル。
 *
 * ロール割り当ては`user.roles_changed`イベントの再生で復元されるが、旧システムの初期admin
 * ユーザーのように、コマンド経由でなく直接role_userへ挿入された(=イベント化されていない)
 * 割り当てが存在しうる。このコマンドは`legacy:export`が書き出したrole_userスナップショットを
 * 元に、そうした行を(UUID対応表でuser_idを付け替えた上で)直接補完する。すでに同じ
 * (user_id, role_id)組が存在する場合は何もしない(イベント再生済みの割り当てを壊さない)。
 *
 * users テーブルが存在する必要があるため、必ず `event-sourcing:replay` の後に実行すること。
 */
class LegacyBackfillRoleAssignmentsCommand extends Command
{
    protected $signature = 'legacy:backfill-roles
        {--path= : legacy:exportの出力先ディレクトリ}
        {--map= : UUID対応表(JSON)の保存先ファイル}';

    protected $description = 'イベント化されていなかったrole_user割り当てをバックフィルする(event-sourcing:replay後に実行)';

    public function handle(): int
    {
        $path = $this->option('path') ?: storage_path('app/legacy-migration/snapshot');
        $mapPath = $this->option('map') ?: storage_path('app/legacy-migration/uuid-map.json');

        $file = "{$path}/role_user.json";
        if (! file_exists($file)) {
            $this->warn("role_user.json が見つかりません({$file})。スキップします。");

            return self::SUCCESS;
        }

        $rows = json_decode(file_get_contents($file));
        if (! is_array($rows)) {
            $rows = [];
        }

        $uuidMap = UuidMap::load($mapPath);

        $inserted = 0;
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $userUuid = $uuidMap->resolve('users', $row->user_id);

            $exists = DB::table('role_user')
                ->where('user_id', $userUuid)
                ->where('role_id', $row->role_id)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('role_user')->insert([
                'user_id' => $userUuid,
                'role_id' => $row->role_id,
                'created_at' => $row->created_at ?? now(),
                'updated_at' => $row->updated_at ?? now(),
            ]);
            $inserted++;
        }

        $this->info("完了。{$inserted}件のrole_user割り当てをバックフィルしました(対象{$file}: ".count($rows).'件)。');

        return self::SUCCESS;
    }
}
