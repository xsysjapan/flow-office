<?php

namespace App\Domain\Attendance\Services;

use Illuminate\Support\Carbon;

/**
 * 週次パターン(ISO曜日1=月〜7=日をキーとする既定値)と、日付単位の上書き
 * (`day_overrides`、キーが存在しない日は週次パターンにフォールバック)から、
 * 特定日の勤務予定(開始/終了時刻・休憩分)を解決する。
 *
 * 週次・月次一括入力の「プレビュー」(永続化しない)と「確定」(コマンド発行)の
 * 両方から使い、解決ロジックを1箇所に集約する(設計原則9: 入口ごとの計算ロジック複製禁止)。
 */
class WeeklyPatternResolver
{
    /**
     * @param  array<int|string, array{start_time: string, end_time: string, break_minutes: int}|null>  $weeklyPattern  ISO曜日(1=月〜7=日)をキーとする
     * @param  array<string, array{start_time: string, end_time: string, break_minutes: int}|null>  $dayOverrides  'Y-m-d'をキーとする
     */
    public function __construct(
        private readonly array $weeklyPattern,
        private readonly array $dayOverrides,
    ) {}

    /**
     * その日がこのパターンの対象外(週次パターンにもキーが無く、日次上書きにもキーが無い)場合は null を返す。
     *
     * @return array{value: array{start_time: string, end_time: string, break_minutes: int}|null, source: 'day_override'|'weekly_pattern'}|null
     */
    public function resolve(Carbon $date): ?array
    {
        $dateKey = $date->toDateString();

        if (array_key_exists($dateKey, $this->dayOverrides)) {
            return ['value' => $this->dayOverrides[$dateKey], 'source' => 'day_override'];
        }

        $weekday = $date->dayOfWeekIso;

        if (array_key_exists($weekday, $this->weeklyPattern) || array_key_exists((string) $weekday, $this->weeklyPattern)) {
            $value = $this->weeklyPattern[$weekday] ?? $this->weeklyPattern[(string) $weekday];

            return ['value' => $value, 'source' => 'weekly_pattern'];
        }

        return null;
    }
}
