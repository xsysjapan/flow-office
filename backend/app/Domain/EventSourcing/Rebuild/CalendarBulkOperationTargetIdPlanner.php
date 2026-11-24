<?php

namespace App\Domain\EventSourcing\Rebuild;

use App\Domain\Attendance\Projectors\CalendarBulkOperationProjector;
use Illuminate\Support\Facades\DB;

/**
 * 一括操作の取消イベント(calendar_bulk_operation.reverted)のうち、対象行のidが旧い体系(再生のたびに変わるランダムなid)のものを見つけ、
 * 新しいid(`CalendarBulkOperationProjector::targetId`)への対応表と書き換え後のpayloadを作る
 * (docs/changesets/20261123-rebuild-drift-fixes/spec.md 論点2・3)。読み取りのみ。
 *
 * - 未補正の判定: 取消イベントのidが、その操作の適用イベントの`targets`から計算できる新idの集合に含まれない。
 * - 対応表: ライブの対象行を、適用イベントの`targets`の要素と(利用者・日付・予定行のid・結果・error_code・previous_snapshot)で突き合わせる。
 *   キーが同じものが複数あれば、内容が同一なので、ライブの行はid順・イベントの要素は位置順で1対1に対応させる。
 */
final class CalendarBulkOperationTargetIdPlanner
{
    public const APPLIED_CLASS = 'calendar_bulk_operation.applied';

    public const REVERTED_CLASS = 'calendar_bulk_operation.reverted';

    private const CHUNK = 200;

    /** @var array<string, list<array<string, mixed>>|null> 操作ID => 適用イベントのtargets(適用イベントが無ければnull) */
    private array $appliedTargets = [];

    /**
     * 未補正の取消イベント(読み取りのみ。ライブの対象行は読まない)。
     *
     * @return list<array{id: int, aggregate_uuid: string, aggregate_version: ?int, event_class: string, raw: string, properties: array<string, mixed>, old_ids: list<string>}>
     */
    public function uncorrectedEvents(): array
    {
        $this->appliedTargets = [];
        $events = [];
        DB::table('stored_events')
            ->where('event_class', self::REVERTED_CLASS)
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($chunk) use (&$events): void {
                foreach ($chunk as $row) {
                    $properties = json_decode((string) $row->event_properties, true, 512, JSON_THROW_ON_ERROR);
                    $operationId = (string) $row->aggregate_uuid;
                    $valid = $this->validIds($operationId);
                    $old = [];
                    foreach ($this->idsOf($properties) as $id) {
                        if (! isset($valid[$id])) {
                            $old[] = $id;
                        }
                    }
                    if ($old !== []) {
                        $events[] = [
                            'id' => (int) $row->id,
                            'aggregate_uuid' => $operationId,
                            'aggregate_version' => $row->aggregate_version !== null ? (int) $row->aggregate_version : null,
                            'event_class' => (string) $row->event_class,
                            'raw' => (string) $row->event_properties,
                            'properties' => $properties,
                            'old_ids' => array_values(array_unique($old)),
                        ];
                    }
                }
            });

        return $events;
    }

    /** 未補正の取消イベントの件数(検出器用)。 */
    public function uncorrectedCount(): int
    {
        return count($this->uncorrectedEvents());
    }

    /**
     * 補正の計画を作る(読み取りのみ)。
     *
     * @return array{events: list<array{id: int, aggregate_uuid: string, aggregate_version: ?int, event_class: string, raw: string, before: array<string, mixed>, after: array<string, mixed>|null}>, mapping: array<string, array<string, string>>, unmapped: list<array{event_id: int, operation_id: string, old_id: string}>}
     */
    public function plan(): array
    {
        $uncorrected = $this->uncorrectedEvents();

        $mapping = [];
        foreach (array_unique(array_column($uncorrected, 'aggregate_uuid')) as $operationId) {
            $mapping[$operationId] = $this->mappingFor((string) $operationId);
        }

        $events = [];
        $unmapped = [];
        foreach ($uncorrected as $event) {
            $operationId = $event['aggregate_uuid'];
            $valid = $this->validIds($operationId);
            $missing = false;
            $after = $event['properties'];
            foreach (['revertedTargetIds', 'excludedTargetIds'] as $key) {
                $ids = [];
                foreach ((array) ($event['properties'][$key] ?? []) as $id) {
                    $id = (string) $id;
                    if (isset($valid[$id])) {
                        $ids[] = $id;
                    } elseif (isset($mapping[$operationId][$id])) {
                        $ids[] = $mapping[$operationId][$id];
                    } else {
                        $unmapped[] = ['event_id' => $event['id'], 'operation_id' => $operationId, 'old_id' => $id];
                        $missing = true;
                        $ids[] = $id;
                    }
                }
                $after[$key] = $ids;
            }
            $events[] = [
                'id' => $event['id'],
                'aggregate_uuid' => $operationId,
                'aggregate_version' => $event['aggregate_version'],
                'event_class' => $event['event_class'],
                'raw' => $event['raw'],
                'before' => $event['properties'],
                'after' => $missing ? null : $after,
            ];
        }

        return ['events' => $events, 'mapping' => $mapping, 'unmapped' => $unmapped];
    }

    /**
     * 1つの操作の対応表(旧id => 新id)。ライブの対象行のうち、適用イベントの要素と対応が付いたものだけ。
     *
     * @return array<string, string>
     */
    private function mappingFor(string $operationId): array
    {
        $targets = $this->targetsOf($operationId);
        if ($targets === null) {
            return [];
        }

        $positions = [];
        foreach ($targets as $position => $target) {
            $positions[$this->key($target)][] = $position;
        }

        $mapping = [];
        $live = DB::table('calendar_bulk_operation_targets')
            ->where('calendar_bulk_operation_id', $operationId)
            ->orderBy('id')
            ->get();
        foreach ($live as $row) {
            $key = $this->key((array) $row);
            if (empty($positions[$key])) {
                continue;
            }
            $position = array_shift($positions[$key]);
            $mapping[(string) $row->id] = CalendarBulkOperationProjector::targetId($operationId, $position);
        }

        return $mapping;
    }

    /** @return array<string, true> 操作の適用イベントから計算できる新idの集合(適用イベントが無ければ空) */
    private function validIds(string $operationId): array
    {
        $valid = [];
        foreach (array_keys($this->targetsOf($operationId) ?? []) as $position) {
            $valid[CalendarBulkOperationProjector::targetId($operationId, (int) $position)] = true;
        }

        return $valid;
    }

    /** @return list<array<string, mixed>>|null */
    private function targetsOf(string $operationId): ?array
    {
        if (! array_key_exists($operationId, $this->appliedTargets)) {
            $row = DB::table('stored_events')
                ->where('event_class', self::APPLIED_CLASS)
                ->where('aggregate_uuid', $operationId)
                ->orderBy('aggregate_version')
                ->first();
            $this->appliedTargets[$operationId] = $row === null
                ? null
                : array_values((array) (json_decode((string) $row->event_properties, true, 512, JSON_THROW_ON_ERROR)['targets'] ?? []));
        }

        return $this->appliedTargets[$operationId];
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return list<string>
     */
    private function idsOf(array $properties): array
    {
        return array_map('strval', array_merge((array) ($properties['revertedTargetIds'] ?? []), (array) ($properties['excludedTargetIds'] ?? [])));
    }

    /** 突き合わせのキー(ライブの行・適用イベントの要素のどちらからも同じ値になるよう正規化する)。 */
    private function key(array $target): string
    {
        $snapshot = $target['previous_snapshot'] ?? null;
        if (is_string($snapshot)) {
            $snapshot = json_decode($snapshot, true, 512, JSON_THROW_ON_ERROR);
        }

        return json_encode([
            (string) ($target['user_id'] ?? ''),
            substr((string) ($target['work_date'] ?? ''), 0, 10),
            $target['employee_calendar_entry_id'] ?? null,
            $target['result'] ?? null,
            $target['error_code'] ?? null,
            $this->canonical($snapshot),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $value = array_map(fn ($item) => $this->canonical($item), $value);
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
