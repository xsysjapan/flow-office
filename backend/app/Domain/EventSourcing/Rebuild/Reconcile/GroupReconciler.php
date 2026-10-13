<?php

namespace App\Domain\EventSourcing\Rebuild\Reconcile;

use App\Domain\EventSourcing\Rebuild\UnsupportedEventSequence;

/**
 * groups の照合(論点19 決定1)。集約UUIDは行の id。
 *
 * イベントによる最終状態: group.created の値を、group.updated の値で順に上書きしたもの(created は status=active)。
 * - created が無い: 行の値から group.created を導出する。集約にイベントが無ければ、グループ種別(・親グループ)の作成イベントの
 *   直後(無ければ列の先頭)。イベントがあれば最初のイベントの直前。
 * - 最終状態が行と違う: 最後のイベントの直後に、行の値の group.updated を導出する(status が active でなければ作成の直後)。
 * 操作者は null(行に作成者の列が無い)。親グループが先に作られるよう、親の浅い順に処理する。
 */
final class GroupReconciler
{
    public const TABLE = 'groups';

    public function reconcile(ReconcileContext $ctx): void
    {
        foreach ($this->parentsFirst($ctx->current->groups) as $row) {
            $ctx->guard('group '.$row['id'], fn () => $this->one($ctx, $row));
        }
    }

    /**
     * @param  list<array<string, mixed>>  $groups
     * @return list<array<string, mixed>>
     */
    private function parentsFirst(array $groups): array
    {
        $byId = [];
        foreach ($groups as $group) {
            $group = Norm::row($group);
            $byId[(string) $group['id']] = $group;
        }
        $depth = function (string $id) use ($byId): int {
            $depth = 0;
            $seen = [];
            while (isset($byId[$id]) && ($byId[$id]['parent_group_id'] ?? null) !== null && ! isset($seen[$id])) {
                $seen[$id] = true;
                $id = (string) $byId[$id]['parent_group_id'];
                $depth++;
            }

            return $depth;
        };
        $ids = array_keys($byId);
        usort($ids, fn (string $a, string $b) => [$depth($a), $a] <=> [$depth($b), $b]);

        return array_map(fn (string $id) => $byId[$id], $ids);
    }

    /** @param  array<string, mixed>  $row */
    private function one(ReconcileContext $ctx, array $row): void
    {
        $id = (string) $row['id'];
        $key = "group {$id}";
        $want = [
            'name' => (string) $row['name'],
            'code' => (string) $row['code'],
            'description' => Norm::str($row['description'] ?? null),
            'parent_group_id' => Norm::str($row['parent_group_id'] ?? null),
            'status' => (string) $row['status'],
        ];
        $groupTypeId = (int) $row['group_type_id'];
        $keyEvents = $ctx->rowsOfClasses($id, ['group.created', 'group.updated']);

        $hasCreated = false;
        foreach ($keyEvents as $event) {
            if ($event->eventClass === 'group.created') {
                $hasCreated = true;
                if ((int) $event->get('groupTypeId') !== $groupTypeId) {
                    throw new UnsupportedEventSequence($key, 'group.created の groupTypeId が行と違う(updated では直せない)');
                }
            }
        }

        $state = null;
        $last = $ctx->afterLast($id);
        if (! $hasCreated) {
            $createdAt = Norm::str($row['created_at'] ?? null);
            $slot = $ctx->beforeFirst($id, $createdAt);
            if ($slot === null) {
                $dep = $ctx->latest($ctx->ref('group_type:'.$groupTypeId), $want['parent_group_id'] !== null ? $ctx->ref('group:'.$want['parent_group_id']) : null);
                $slot = $dep !== null ? $ctx->afterRef($dep, $createdAt) : $ctx->head($createdAt);
            } else {
                $slot = $ctx->notBefore($slot, $ctx->latest($ctx->ref('group_type:'.$groupTypeId), $want['parent_group_id'] !== null ? $ctx->ref('group:'.$want['parent_group_id']) : null));
            }
            $createdSlot = $ctx->emit(self::TABLE, $slot, 'group.created', $id, [
                'groupTypeId' => $groupTypeId,
                'name' => $want['name'],
                'code' => $want['code'],
                'description' => $want['description'],
                'parentGroupId' => $want['parent_group_id'],
                'actorUserId' => null,
            ]);
            $ctx->registerRef('group:'.$id, $createdSlot);
            $state = ['status' => 'active'] + $want;
            $last ??= $createdSlot;
        }

        foreach ($keyEvents as $event) {
            $payload = $event->payload;
            if ($event->eventClass === 'group.created') {
                $state = [
                    'name' => (string) $payload['name'], 'code' => (string) $payload['code'],
                    'description' => Norm::str($payload['description'] ?? null), 'parent_group_id' => Norm::str($payload['parentGroupId'] ?? null),
                    'status' => 'active',
                ];
            } else {
                $state = [
                    'name' => (string) $payload['name'], 'code' => (string) $payload['code'],
                    'description' => Norm::str($payload['description'] ?? null), 'parent_group_id' => Norm::str($payload['parentGroupId'] ?? null),
                    'status' => (string) $payload['status'],
                ] + ($state ?? []);
            }
        }

        $differences = [];
        foreach ($want as $column => $value) {
            if (! array_key_exists($column, $state ?? []) || $state[$column] !== $value) {
                $differences[] = $column;
            }
        }
        if ($keyEvents !== [] && ($differences !== [] || ! $hasCreated)) {
            $ctx->mismatch(self::TABLE, $key, $hasCreated ? '最終状態が違う: '.implode(', ', $differences) : 'group.created が無い'.($differences === [] ? '' : '、最終状態が違う: '.implode(', ', $differences)));
        }
        if ($differences === []) {
            return;
        }

        $ctx->emit(self::TABLE, $last, 'group.updated', $id, [
            'name' => $want['name'],
            'code' => $want['code'],
            'description' => $want['description'],
            'parentGroupId' => $want['parent_group_id'],
            'status' => $want['status'],
            'actorUserId' => null,
        ]);
    }
}
