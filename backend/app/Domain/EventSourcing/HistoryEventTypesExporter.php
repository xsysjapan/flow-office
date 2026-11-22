<?php

namespace App\Domain\EventSourcing;

/**
 * 履歴画面に表示するイベント種別の一覧を、frontendの型定義として書き出す。
 *
 * 種別の正は config/event-sourcing.php の event_class_map(エイリアス `ドメイン.種別`)。
 * 画面ごとの接頭辞(ドット込み)に合うエイリアスを config上の登場順で抜き出し、
 * frontend/src/generated/historyEventTypes.ts の `as const` 配列と型にする。
 * frontend/src/utils/statusLabels.ts の表示名・詳細文はこの型で網羅させ、漏れを tsc で検出する。
 */
final class HistoryEventTypesExporter
{
    /**
     * 画面ごとの接頭辞。接頭辞はドットまで含めて比較する(`special_leave.` は `special_leave_account.` に当たらない)。
     *
     * @var list<array{key: string, prefixes: list<string>}>
     */
    private const SECTIONS = [
        ['key' => 'paidLeave', 'prefixes' => ['paid_leave_account.', 'paid_leave_request.']],
        ['key' => 'specialLeave', 'prefixes' => ['special_leave.', 'special_leave_account.']],
        ['key' => 'compensatoryLeave', 'prefixes' => ['compensatory_leave.', 'compensatory_leave_account.']],
        ['key' => 'asset', 'prefixes' => ['asset.']],
    ];

    private const HEADER = [
        '// このファイルは backend の `php artisan events:export-history-types` で生成する。手で編集しない。',
        '// 履歴画面に表示するイベント種別の一覧(backend/config/event-sourcing.php の event_class_map から抽出)。',
        '// 表示名・詳細文の網羅は frontend/src/utils/statusLabels.ts の型で検査する。',
    ];

    /** 生成ファイルの出力先(リポジトリ内の frontend/)。 */
    public static function outputPath(): string
    {
        return base_path('../frontend/src/generated/historyEventTypes.ts');
    }

    /** 生成ファイルの中身。コミット済みファイルとの比較・書き出しに使う。 */
    public function render(): string
    {
        $eventClassMap = config('event-sourcing.event_class_map');

        $blocks = [];
        foreach (self::SECTIONS as $section) {
            $types = self::extract($eventClassMap, $section['prefixes']);
            $typeName = ucfirst($section['key']).'HistoryEventType';

            $lines = ["export const {$section['key']}HistoryEventTypes = ["];
            foreach ($types as $type) {
                $lines[] = "  '{$type}',";
            }
            $lines[] = '] as const';
            $lines[] = "export type {$typeName} = (typeof {$section['key']}HistoryEventTypes)[number]";

            $blocks[] = implode("\n", $lines);
        }

        return implode("\n", self::HEADER)."\n\n".implode("\n\n", $blocks)."\n";
    }

    /**
     * エイリアスの一覧から、いずれかの接頭辞に前方一致するものを config上の登場順で抜き出す。
     *
     * @param  array<string, mixed>  $eventClassMap  エイリアス => イベントクラス
     * @param  list<string>  $prefixes  ドット込みの接頭辞
     * @return list<string>
     */
    public static function extract(array $eventClassMap, array $prefixes): array
    {
        $matched = [];
        foreach (array_keys($eventClassMap) as $alias) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with((string) $alias, $prefix)) {
                    $matched[] = (string) $alias;
                    break;
                }
            }
        }

        return $matched;
    }
}
