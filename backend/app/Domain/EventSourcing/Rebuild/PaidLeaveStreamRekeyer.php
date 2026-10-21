<?php

namespace App\Domain\EventSourcing\Rebuild;

use App\Domain\PaidLeaveAccount\Aggregates\PaidLeaveAccountAggregate;

/**
 * 有給口座・付与予定のAggregateIdを社員から分離する、stored_events の全行を1行1行に写す純粋な変換
 * (docs/changesets/20261021-paid-leave-aggregate-stream-id 論点3)。DBには触れない。
 *
 * - paid_leave_account.*: payload に userId(元の aggregate_uuid)を入れ、aggregate_uuid と meta の aggregate-root-uuid を
 *   PaidLeaveAccountAggregate::streamIdFor(userId) に替える。既に新IDの行(payload の userId から作ったIDが aggregate_uuid と
 *   一致する行)は変えない(2回かけても同じ)。
 * - paid_leave_schedule.*: 取り除く(自動付与は使われていない前提。事前条件で使われた形跡があれば変換しない)。
 * - 版は影響するストリーム(新しい有給口座、行が抜けて詰まる元のストリーム)だけを id順に1から振り直し、meta の
 *   aggregate-root-version も合わせる。それ以外の行は id・版・payload・meta・created_at を変えない。残る行の id は変えない。
 */
final class PaidLeaveStreamRekeyer
{
    public const ACCOUNT_PREFIX = 'paid_leave_account.';

    public const SCHEDULE_PREFIX = 'paid_leave_schedule.';

    /** 1件でもあれば付与予定が実際に使われていたとみなし、変換しない付与予定の種類。 */
    public const BLOCKING_SCHEDULE_CLASSES = [
        'paid_leave_schedule.entry_granted',
        'paid_leave_schedule.assessment_overridden',
        'paid_leave_schedule.entry_manually_edited',
    ];

    public const SCHEDULED_BATCH_SOURCE = 'scheduled_batch';

    /**
     * 事前条件の違反(説明 => 件数)。空なら満たしている。手動付与(source='manual')・移行(carried_over)は数えない。
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    public function violations(array $rows): array
    {
        $violations = [];
        foreach ($rows as $row) {
            $class = (string) $row['event_class'];
            if (in_array($class, self::BLOCKING_SCHEDULE_CLASSES, true)) {
                $key = "{$class} がある(付与予定が実際に使われていた)";
                $violations[$key] = ($violations[$key] ?? 0) + 1;
            } elseif ($class === 'paid_leave_account.grant_created' && ($row['event_properties']['source'] ?? null) === self::SCHEDULED_BATCH_SOURCE) {
                $key = "{$class} の source='scheduled_batch' がある(付与予定からの一括付与が使われていた)";
                $violations[$key] = ($violations[$key] ?? 0) + 1;
            }
        }

        return $violations;
    }

    /**
     * @param  list<array<string, mixed>>  $rows  stored_events の全行(Row形。順不同でもよい)
     * @param  bool  $checkPreconditions  false なら事前条件を見ずに変換する(作り直しの最後の工程。付与予定の行は取り除かれ、件数は結果に残る)
     */
    public function rekey(array $rows, bool $checkPreconditions = true): PaidLeaveRekeyResult
    {
        usort($rows, fn (array $a, array $b) => (int) $a['id'] <=> (int) $b['id']);

        $violations = $this->violations($rows);
        if ($checkPreconditions && $violations !== []) {
            return new PaidLeaveRekeyResult($rows, false, 0, [], [], $violations, 0, 0);
        }

        $out = [];
        $affected = [];
        $mapping = [];
        $removed = [];
        $rekeyed = 0;

        foreach ($rows as $row) {
            $class = (string) $row['event_class'];
            $uuid = $row['aggregate_uuid'];

            if (str_starts_with($class, self::SCHEDULE_PREFIX)) {
                $removed[$class] = ($removed[$class] ?? 0) + 1;
                if ($uuid !== null) {
                    $affected[(string) $uuid] = true;
                }

                continue;
            }

            if (str_starts_with($class, self::ACCOUNT_PREFIX) && $uuid !== null && ! $this->isRekeyed($row)) {
                $userId = (string) $uuid;
                $newId = PaidLeaveAccountAggregate::streamIdFor($userId);
                $row['event_properties'] = ['userId' => $userId] + $row['event_properties'];
                $row['aggregate_uuid'] = $newId;
                $row['meta_data']['aggregate-root-uuid'] = $newId;
                $mapping[$userId] = $newId;
                $affected[$userId] = true;
                $affected[$newId] = true;
                $rekeyed++;
            }

            $out[] = $row;
        }

        $versions = [];
        $changedRows = 0;
        foreach ($out as $i => $row) {
            $uuid = $row['aggregate_uuid'];
            if ($uuid === null || ! isset($affected[(string) $uuid])) {
                continue;
            }
            $version = ($versions[(string) $uuid] ?? 0) + 1;
            $versions[(string) $uuid] = $version;
            if ((int) $row['aggregate_version'] !== $version || ($row['meta_data']['aggregate-root-version'] ?? null) !== $version) {
                $changedRows++;
            }
            $out[$i]['aggregate_version'] = $version;
            $out[$i]['meta_data']['aggregate-root-version'] = $version;
        }

        return new PaidLeaveRekeyResult($out, true, $rekeyed, $removed, $mapping, $violations, count($versions), $changedRows);
    }

    /**
     * 行の id を1から振り直す(作り直しの出力用。meta の stored-event-id も合わせる)。単独の付け替えでは使わない(残る行の id は変えない)。
     *
     * @param  list<array<string, mixed>>  $rows  id順
     * @return list<array<string, mixed>>
     */
    public function compactIds(array $rows): array
    {
        foreach ($rows as $i => $row) {
            $id = $i + 1;
            $rows[$i]['id'] = $id;
            $rows[$i]['meta_data']['stored-event-id'] = $id;
        }

        return $rows;
    }

    /** payload の userId から作ったストリームIDが aggregate_uuid と一致する(付け替え済み)。 */
    public function isRekeyed(array $row): bool
    {
        $userId = $row['event_properties']['userId'] ?? null;

        return is_string($userId) && $userId !== ''
            && $row['aggregate_uuid'] === PaidLeaveAccountAggregate::streamIdFor($userId);
    }
}
