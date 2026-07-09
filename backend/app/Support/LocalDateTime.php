<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * システム内部ではタイムゾーンなしの壁時計時刻を保存し、APIの境界では必ず
 * オフセット付きのISO8601で送受信する、という方針の変換をこの1箇所に集約する。
 *
 * - 保存する壁時計時刻は「誰の時刻か」(通常はレコードの所有者)のタイムゾーンでの
 *   壁時計であることを前提とする。DBのdatetimeカラム自体はタイムゾーン情報を持たない。
 * - APIから受け取ったオフセット付き文字列は {@see parse()} で該当ユーザーのタイムゾーンに
 *   変換してから保存する(結果のCarbonをそのままEloquentのdatetimeキャスト属性に代入すれば、
 *   タイムゾーンなしの壁時計時刻として正しく保存される)。
 * - 保存済みの壁時計時刻をAPIへ出力する際は {@see toIso8601()} で該当ユーザーの
 *   タイムゾーンのオフセットを付与する。
 */
class LocalDateTime
{
    /**
     * リクエストのvalidationルールで使う。オフセット(Zまたは±HH:MM)を必須にする。
     * 例: `'actual_start_at' => ['nullable', 'date', LocalDateTime::OFFSET_REQUIRED_RULE]`
     */
    public const OFFSET_REQUIRED_RULE = 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/';

    public static function parse(string $iso8601WithOffset, string $timezone): Carbon
    {
        return Carbon::parse($iso8601WithOffset)->setTimezone($timezone);
    }

    public static function toIso8601(?Carbon $storedNaive, string $timezone): ?string
    {
        if ($storedNaive === null) {
            return null;
        }

        return Carbon::createFromFormat('Y-m-d H:i:s', $storedNaive->format('Y-m-d H:i:s'), $timezone)->toIso8601String();
    }

    public static function now(string $timezone): Carbon
    {
        return Carbon::now($timezone);
    }
}
