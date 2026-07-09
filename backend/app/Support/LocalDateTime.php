<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * システム内部ではタイムゾーンなしの壁時計時刻を保存し、APIの境界では必ず
 * オフセット付きのISO8601で送受信する、という方針の変換をこの1箇所に集約する。
 *
 * 変換には2つの系統がある (docs/03-architecture.md 3.4)。
 *
 * 1. タイムゾーン名で変換する系統({@see parse()} / {@see toIso8601()} / {@see now()}):
 *    last_login_at や submitted_at のような一般的な日時に使う。APIでのオフセットは
 *    システムのデフォルトタイムゾーン(`system_settings.default_timezone`)を渡して使う。
 * 2. UTCオフセット(分)そのもので変換する系統({@see splitOffset()} / {@see formatWithOffsetMinutes()}):
 *    勤怠の勤務実績(attendance_days / attendance_punches)に使う。海外出張などで勤務日ごとに
 *    現地時刻(オフセット)が変わるため、users.timezone のような固定のタイムゾーン名ではなく、
 *    その勤務日・打刻が実際に記録されたUTCオフセットをそのまま保持する。
 */
class LocalDateTime
{
    /**
     * リクエストのvalidationルールで使う。オフセット(Zまたは±HH:MM)を必須にする。
     * 例: `'actual_start_at' => ['nullable', 'date', LocalDateTime::OFFSET_REQUIRED_RULE]`
     */
    public const OFFSET_REQUIRED_RULE = 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/';

    private const OFFSET_PATTERN = '/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2}:\d{2})(?:\.\d+)?(Z|[+-]\d{2}:\d{2})$/';

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

    /**
     * オフセット付きISO8601文字列を、タイムゾーン変換をせずに「入力された通りの壁時計時刻」と
     * 「そのオフセット(分)」に分解する。勤怠の勤務実績のように、値そのものが自分自身の
     * オフセットを保持するケースで使う。
     *
     * @return array{0: Carbon, 1: int} [ナイーブな壁時計時刻, UTCオフセット(分)]
     */
    public static function splitOffset(string $iso8601WithOffset): array
    {
        if (! preg_match(self::OFFSET_PATTERN, $iso8601WithOffset, $matches)) {
            throw new InvalidArgumentException("オフセット付きISO8601形式ではありません: {$iso8601WithOffset}");
        }

        [, $date, $time, $offsetRaw] = $matches;

        $offsetMinutes = $offsetRaw === 'Z' ? 0 : self::offsetStringToMinutes($offsetRaw);

        return [Carbon::createFromFormat('Y-m-d H:i:s', "{$date} {$time}"), $offsetMinutes];
    }

    /**
     * ナイーブな壁時計時刻に、指定したUTCオフセット(分)を付与してISO8601文字列を組み立てる。
     */
    public static function formatWithOffsetMinutes(?Carbon $storedNaive, ?int $offsetMinutes): ?string
    {
        if ($storedNaive === null) {
            return null;
        }

        $offsetMinutes ??= 0;
        $sign = $offsetMinutes >= 0 ? '+' : '-';
        $abs = abs($offsetMinutes);

        return $storedNaive->format('Y-m-d\TH:i:s').sprintf('%s%02d:%02d', $sign, intdiv($abs, 60), $abs % 60);
    }

    private static function offsetStringToMinutes(string $offset): int
    {
        $sign = $offset[0] === '-' ? -1 : 1;
        [$hours, $minutes] = array_map('intval', explode(':', substr($offset, 1)));

        return $sign * ($hours * 60 + $minutes);
    }
}
