<?php

namespace App\Services\EventSourcing;

use App\Domain\EventSourcing\Rebuild\LegacyBooleanPropertyDetector;
use App\Domain\EventSourcing\Rebuild\LegacyCalendarBulkOperationTargetIdDetector;
use App\Domain\EventSourcing\Rebuild\LegacyUserSettingsDetector;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * 「補正待ち」か(旧形式のイベントの検出器のどれかが陽性か)を返す
 * (docs/changesets/20261124-correction-pending-maintenance/spec.md 論点1)。
 * 全APIのミドルウェアとスケジューラーから呼ばれるため、結果を`TTL_SECONDS`だけキャッシュする。
 * 補正のコマンドが終わったとき(成功・失敗とも)に`forget()`で消す(`AdminCommandRunner`・`ReadModelRebuildRunService`)。読むだけで、書かない。
 */
class CorrectionPendingService
{
    public const CACHE_KEY = 'correction-pending';

    public const TTL_SECONDS = 60;

    public const MESSAGE = 'システムのメンテナンス(データの補正の待ち)中です。管理者が補正を実行するまで、しばらくお待ちください。';

    public function isPending(): bool
    {
        try {
            return (bool) Cache::remember(self::CACHE_KEY, self::TTL_SECONDS, fn (): bool => $this->detect());
        } catch (Throwable) {
            // テーブルが無い環境(migrate前)・キャッシュの障害では、止めない。
            return false;
        }
    }

    public function forget(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (Throwable) {
            // キャッシュの障害で、補正の記録の更新を失敗させない。
        }
    }

    private function detect(): bool
    {
        return (new LegacyBooleanPropertyDetector)->count() > 0
            || (new LegacyUserSettingsDetector)->count() > 0
            || (new LegacyCalendarBulkOperationTargetIdDetector)->count() > 0;
    }
}
