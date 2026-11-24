<?php

namespace App\Domain\EventSourcing\Rebuild;

/**
 * 利用者の作成系イベントのうち、タイムゾーン・テナントIDのキーが無い(未補正の)ものの件数
 * (docs/changesets/20261124-user-projection-settings-at-replay/spec.md 論点2)。読み取りのみ。
 * 件数が0でない間は、作り直すと Projector が「未補正のイベント」で例外になる(または値が変わる)ため、ReadModelの作り直しを拒否する。
 */
final class LegacyUserSettingsDetector
{
    public const COMMAND = 'events:fix-user-settings-at-replay';

    public const COMBINED_COMMAND = 'events:fix-rebuild-determinism';

    public function count(): int
    {
        return (new UserSettingsAtReplayPlanner)->uncorrectedCount();
    }

    /** 作り直しの拒否のときの文言(件数つき)。 */
    public static function message(int $count): string
    {
        return "タイムゾーン・テナントIDが記録されていない(未補正の)利用者のイベントが{$count}件あります。先に運用コマンド『利用者の設定値の補正』(".self::COMMAND.')を実行してください'
            .'(一括操作の取消イベントの補正も必要な場合は、『再生の決定性の補正』('.self::COMBINED_COMMAND.')でまとめて実行します)。';
    }

    /** 補正のコマンド(別の補正)が拒否するときの文言。2つの補正は順に実行できないため、まとめのコマンドを案内する。 */
    public static function messageForFixCommand(int $count): string
    {
        return "タイムゾーン・テナントIDが記録されていない(未補正の)利用者のイベントが{$count}件あります。このコマンドでは実行できません。"
            .'先に運用コマンド『再生の決定性の補正』('.self::COMBINED_COMMAND.')を実行してください。';
    }
}
