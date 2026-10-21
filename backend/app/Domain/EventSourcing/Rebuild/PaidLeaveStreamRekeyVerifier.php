<?php

namespace App\Domain\EventSourcing\Rebuild;

use App\Domain\PaidLeaveAccount\Aggregates\PaidLeaveAccountAggregate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 有給口座・付与予定のストリーム付け替え(PaidLeaveStreamRekeyer)の検証。付け替え後の表($table)と元の表($source)を読む(読み取りのみ)。
 * 既存の作り直しの verify(id・版の連番)は使わない(付与予定の行を取り除くと id が飛ぶため)。
 *
 * (a) aggregate_uuid が users.id の行は user.* だけ
 * (b) paid_leave_account.* で、payload の userId から作ったIDが aggregate_uuid(と meta の aggregate-root-uuid)と一致する
 * (c) paid_leave_schedule.* が0件
 * (d) 有給口座・付与予定・社員以外の行が、元の表と id・版・payload・meta・created_at すべて一致する(行の過不足も違反)
 * (e) EventRestorabilityChecker が通る
 */
final class PaidLeaveStreamRekeyVerifier
{
    private const CHUNK = 500;

    private const MAX_REPORTED = 20;

    /** @return list<string> 違反の説明(空なら問題なし) */
    public function verify(string $table = EventStoreRebuildWriter::TABLE, string $source = 'stored_events'): array
    {
        if (! Schema::hasTable($table)) {
            return ["{$table} が存在しない"];
        }

        $userIds = array_flip(array_map('strval', DB::table('users')->pluck('id')->all()));
        $problems = [];
        $report = function (string $key, string $message) use (&$problems): void {
            $count = count(array_filter($problems, fn (string $p) => str_starts_with($p, "({$key})")));
            if ($count < self::MAX_REPORTED) {
                $problems[] = "({$key}) {$message}";
            } elseif ($count === self::MAX_REPORTED) {
                $problems[] = "({$key}) 以降は省略";
            }
        };

        // (d) の元の行: 対象外(有給口座・付与予定・社員)を除いた行のハッシュ。
        $expected = [];
        DB::table($source)->orderBy('id')->chunkById(self::CHUNK, function ($chunk) use (&$expected, $userIds): void {
            foreach ($chunk as $row) {
                if (! $this->isExcludedFromComparison($row, $userIds)) {
                    $expected[(int) $row->id] = $this->fingerprint($row);
                }
            }
        });

        DB::table($table)->orderBy('id')->chunkById(self::CHUNK, function ($chunk) use ($userIds, &$expected, $report): void {
            foreach ($chunk as $row) {
                $class = (string) $row->event_class;
                $uuid = $row->aggregate_uuid !== null ? (string) $row->aggregate_uuid : null;

                if ($uuid !== null && isset($userIds[$uuid]) && ! str_starts_with($class, 'user.')) {
                    $report('a', "社員IDのストリームに user.* 以外の行がある: id={$row->id} {$class}");
                }
                if (str_starts_with($class, PaidLeaveStreamRekeyer::ACCOUNT_PREFIX)) {
                    $payload = json_decode((string) $row->event_properties, true) ?: [];
                    $meta = json_decode((string) ($row->meta_data ?? '[]'), true) ?: [];
                    $userId = $payload['userId'] ?? null;
                    if (! is_string($userId) || $userId === '' || PaidLeaveAccountAggregate::streamIdFor($userId) !== $uuid) {
                        $report('b', "有給口座の行の aggregate_uuid が payload の userId から作ったIDと一致しない: id={$row->id} {$class}");
                    } elseif (($meta['aggregate-root-uuid'] ?? null) !== $uuid) {
                        $report('b', "有給口座の行の meta の aggregate-root-uuid が aggregate_uuid と一致しない: id={$row->id} {$class}");
                    }
                }
                if (str_starts_with($class, PaidLeaveStreamRekeyer::SCHEDULE_PREFIX)) {
                    $report('c', "付与予定の行が残っている: id={$row->id} {$class}");
                }

                if (! $this->isExcludedFromComparison($row, $userIds)) {
                    $id = (int) $row->id;
                    if (! array_key_exists($id, $expected)) {
                        $report('d', "元の表に無い行がある: id={$id} {$class}");
                    } elseif ($expected[$id] !== $this->fingerprint($row)) {
                        $report('d', "元の表と内容が違う: id={$id} {$class}");
                        unset($expected[$id]);
                    } else {
                        unset($expected[$id]);
                    }
                }
            }
        });
        foreach (array_keys($expected) as $id) {
            $report('d', "元の表の行が無くなっている: id={$id}");
        }

        foreach ((new EventRestorabilityChecker)->checkTable($table) as $class => $failure) {
            $problems[] = "(e) 現行のイベントクラスへ復元できない: {$class} {$failure['count']} 件(先頭 id={$failure['first_id']}) {$failure['message']}";
        }

        return $problems;
    }

    /** 比較の対象外: 有給口座・付与予定の行、社員のストリーム(aggregate_uuid が users.id)の行。 */
    private function isExcludedFromComparison(object $row, array $userIds): bool
    {
        $class = (string) $row->event_class;

        return str_starts_with($class, PaidLeaveStreamRekeyer::ACCOUNT_PREFIX)
            || str_starts_with($class, PaidLeaveStreamRekeyer::SCHEDULE_PREFIX)
            || ($row->aggregate_uuid !== null && isset($userIds[(string) $row->aggregate_uuid]));
    }

    /** id・版・イベント種別・event_version・payload・meta・created_at の正規化したハッシュ(JSONの書式の差を吸収する)。 */
    private function fingerprint(object $row): string
    {
        return hash('sha256', json_encode([
            (int) $row->id,
            $row->aggregate_uuid,
            $row->aggregate_version !== null ? (int) $row->aggregate_version : null,
            (string) $row->event_class,
            (int) ($row->event_version ?? 1),
            $this->canonical(json_decode((string) $row->event_properties, true)),
            $this->canonical(json_decode((string) ($row->meta_data ?? '[]'), true)),
            (string) $row->created_at,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function canonical(mixed $value): mixed
    {
        if (is_array($value)) {
            $isList = array_is_list($value);
            $value = array_map(fn ($v) => $this->canonical($v), $value);
            if (! $isList) {
                ksort($value);
            }
        }

        return $value;
    }
}
