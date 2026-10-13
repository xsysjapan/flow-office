<?php

namespace App\Domain\EventSourcing\Rebuild\Reconcile;

use Carbon\CarbonImmutable;

/**
 * 照合で行の値とイベントの値を比べるための正規化(DBドライバーによる型・書式の違いを吸収する)。
 */
final class Norm
{
    public static function str(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    public static function int(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    public static function bool(mixed $value): bool
    {
        return (bool) $value;
    }

    /** 日付(Y-m-d)。dateキャストで日時文字列になっている値の日付部分を切り出す。 */
    public static function date(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : substr((string) $value, 0, 10);
    }

    /** ReadModelの日時列(UTCで保存された文字列)を Y-m-d H:i:s にそろえる。 */
    public static function rowDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse((string) $value, 'UTC')->format('Y-m-d H:i:s');
    }

    /** イベントの日時文字列を、Projectorの databaseDate と同じ規則で Y-m-d H:i:s(UTC)にそろえる。 */
    public static function eventDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse((string) $value)->utc()->format('Y-m-d H:i:s');
    }

    /** 行の日時列(UTC)を、Projectorで再生すると同じ値になるイベントのpayload用の文字列にする。 */
    public static function payloadDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse((string) $value, 'UTC')->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * 行を値の配列にする(DB::tableの stdClass も受ける)。
     *
     * @return array<string, mixed>
     */
    public static function row(object|array $row): array
    {
        return (array) $row;
    }
}
