<?php

namespace App\Domain\EventSourcing\Rebuild;

/**
 * 対象行のidが旧い体系のままの取消イベント(calendar_bulk_operation.reverted)の件数
 * (docs/changesets/20261123-rebuild-drift-fixes/spec.md 論点3)。読み取りのみ。
 * 件数が0でない間は、作り直すと旧idの行が消えて対応表を作れなくなるため、ReadModelの作り直しを拒否する。
 */
final class LegacyCalendarBulkOperationTargetIdDetector
{
    public const COMMAND = 'events:fix-calendar-bulk-operation-target-ids';

    public function count(): int
    {
        return (new CalendarBulkOperationTargetIdPlanner)->uncorrectedCount();
    }

    /** 拒否のときの文言(件数つき)。 */
    public static function message(int $count): string
    {
        return "対象行のidが旧い体系の一括操作の取消イベントが{$count}件あります。先に運用コマンド『一括操作の対象行idの補正』(".self::COMMAND.')を実行してください。';
    }

    /** 利用者の設定値の補正が拒否するときの文言。2つの補正は順に実行できないため、まとめのコマンドを案内する。 */
    public static function messageForFixCommand(int $count): string
    {
        return "対象行のidが旧い体系の一括操作の取消イベントが{$count}件あります。このコマンドでは実行できません。"
            .'先に運用コマンド『再生の決定性の補正』('.LegacyUserSettingsDetector::COMBINED_COMMAND.')を実行してください。';
    }
}
