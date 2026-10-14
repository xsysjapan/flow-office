<?php

namespace App\Domain\EventSourcing\Rebuild\Reconcile;

use Ramsey\Uuid\Uuid;

/**
 * group_feature_assignments の照合(論点19 決定1)。集約UUIDは uuid5(URL, 'group-access:'.groupId)。
 *
 * イベントによる最終状態: (グループ, Feature)ごとに feature.assigned_to_group・feature.removed_from_group を順に畳み込んだもの
 * (割当て済みなら assigned_by は最後の assigned_to_group の操作者)。
 * - 行があり、(グループ, Feature)のイベントが1件も無い: feature.assigned_to_group を導出する。集約にイベントが無ければグループの作成
 *   イベント・割当者の作成イベントのうち後ろの直後(無ければ列の先頭)。イベントがあれば最初のイベントの直前(ただしグループの作成より前には置かない)。
 * - 行があり、最終状態が違う(removed 済み・assigned_by が違う): 最後のイベントの直後に feature.assigned_to_group を導出する。
 * - 行が無く、最終状態では割り当て済み(余分): 最後のイベントの直後に feature.removed_from_group を導出する。
 * 操作者は行の assigned_by(removed は null)。
 */
final class GroupFeatureAssignmentReconciler
{
    public const TABLE = 'group_feature_assignments';

    private const CLASSES = ['feature.assigned_to_group', 'feature.removed_from_group'];

    public function reconcile(ReconcileContext $ctx): void
    {
        /** @var array<string, array<int, array<string, mixed>>> $rowsByGroup */
        $rowsByGroup = [];
        foreach ($ctx->current->groupFeatureAssignments as $row) {
            $row = Norm::row($row);
            $rowsByGroup[(string) $row['group_id']][(int) $row['feature_id']] = $row;
        }

        // イベントのあるグループ(集約UUIDからグループIDは引けないため、イベントのpayloadから集める)。
        $groupIds = array_keys($rowsByGroup);
        foreach ($ctx->rows() as $event) {
            if (in_array($event->eventClass, self::CLASSES, true)) {
                $groupIds[] = (string) $event->get('groupId');
            }
        }
        $groupIds = array_values(array_unique($groupIds));
        sort($groupIds);

        foreach ($groupIds as $groupId) {
            $ctx->guard('group_feature_assignments group '.$groupId, fn () => $this->group($ctx, $groupId, $rowsByGroup[$groupId] ?? []));
        }
    }

    /** @param  array<int, array<string, mixed>>  $rows  feature_id => 行 */
    private function group(ReconcileContext $ctx, string $groupId, array $rows): void
    {
        $aggregate = Uuid::uuid5(Uuid::NAMESPACE_URL, 'group-access:'.$groupId)->toString();
        $events = $ctx->rowsOfClasses($aggregate, self::CLASSES);

        /** @var array<int, array{assigned: bool, assigned_by: ?string}> $state */
        $state = [];
        $hasEvents = [];
        foreach ($events as $event) {
            $featureId = (int) $event->get('featureId');
            $hasEvents[$featureId] = true;
            if ($event->eventClass === 'feature.assigned_to_group') {
                $state[$featureId] = ['assigned' => true, 'assigned_by' => Norm::str($event->get('actorUserId'))];
            } else {
                $state[$featureId] = ['assigned' => false, 'assigned_by' => null];
            }
        }

        $featureIds = array_values(array_unique(array_merge(array_keys($rows), array_keys($state))));
        sort($featureIds);

        foreach ($featureIds as $featureId) {
            $row = $rows[$featureId] ?? null;
            $final = $state[$featureId] ?? ['assigned' => false, 'assigned_by' => null];
            $key = "group_feature_assignments {$groupId}/{$featureId}";

            if ($row === null) {
                if (! $final['assigned']) {
                    continue;
                }
                $ctx->mismatch(self::TABLE, $key, 'assigned_by: イベント '.ReconcileContext::show($final['assigned_by']).' → 行 null', ['feature.removed_from_group']);
                $ctx->emit(self::TABLE, $ctx->afterLast($aggregate), 'feature.removed_from_group', $aggregate, [
                    'groupId' => $groupId, 'featureId' => $featureId, 'actorUserId' => null,
                ]);

                continue;
            }

            $assignedBy = Norm::str($row['assigned_by'] ?? null);
            if ($final['assigned'] && $final['assigned_by'] === $assignedBy) {
                continue;
            }

            $createdAt = Norm::str($row['created_at'] ?? null);
            $eventAt = null;
            if (isset($hasEvents[$featureId])) {
                $reason = $final['assigned']
                    ? 'assigned_by: イベント '.ReconcileContext::show($final['assigned_by']).' → 行 '.ReconcileContext::show($assignedBy)
                    : '割り当て: イベント 解除済み → 行 あり(assigned_by: '.ReconcileContext::show($assignedBy).')';
                $ctx->mismatch(self::TABLE, $key, $reason, ['feature.assigned_to_group']);
                $slot = $ctx->afterLast($aggregate);
            } else {
                $eventAt = $createdAt;
                $dep = $ctx->latest($ctx->ref('group:'.$groupId), $assignedBy !== null ? $ctx->ref('user:'.$assignedBy) : null);
                $slot = $ctx->beforeFirst($aggregate, $createdAt);
                $slot = $slot !== null
                    ? $ctx->notBefore($slot, $dep)
                    : ($dep !== null ? $ctx->afterRef($dep, $createdAt) : $ctx->head($createdAt));
            }
            $ctx->emit(self::TABLE, $slot, 'feature.assigned_to_group', $aggregate, [
                'groupId' => $groupId, 'featureId' => $featureId, 'actorUserId' => $assignedBy,
            ], $eventAt);
        }
    }
}
