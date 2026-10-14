<?php

namespace App\Domain\EventSourcing\Rebuild\Reconcile;

use App\Domain\EventSourcing\Rebuild\UnsupportedEventSequence;
use Ramsey\Uuid\Uuid;

/**
 * roles の照合(論点19 決定1)。集約UUIDは uuid5(URL, 'role:'.code)。
 *
 * イベントによる最終状態: role.created の値を、role.updated の値で順に上書きしたもの(created は status=active)。
 * - created が無い: 行の値(isSystem を含む)から role.created を導出する。集約にイベントが無ければ列の先頭、あれば最初のイベントの直前。
 * - 最終状態が行と違う: 最後のイベントの直後に、行の値の role.updated を導出する(status が active でなければ作成の直後)。
 * 操作者は null(行に作成者の列が無い)。集約のイベントには role.permissions_changed・features_changed も含まれるが、
 * 作成・更新の判定には role.created・role.updated だけを使う。
 */
final class RoleReconciler
{
    public const TABLE = 'roles';

    public static function aggregateOf(string $code): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'role:'.$code)->toString();
    }

    public function reconcile(ReconcileContext $ctx): void
    {
        foreach ($ctx->current->roles as $row) {
            $ctx->guard('role '.$row['code'], fn () => $this->one($ctx, Norm::row($row)));
        }
    }

    /** @param  array<string, mixed>  $row */
    private function one(ReconcileContext $ctx, array $row): void
    {
        $id = (int) $row['id'];
        $code = (string) $row['code'];
        $key = "role {$code}";
        $aggregate = self::aggregateOf($code);
        $want = [
            'name' => (string) $row['name'],
            'description' => Norm::str($row['description'] ?? null),
            'status' => (string) $row['status'],
        ];
        $keyEvents = $ctx->rowsOfClasses($aggregate, ['role.created', 'role.updated']);

        $hasCreated = false;
        foreach ($keyEvents as $event) {
            if ($event->eventClass === 'role.created') {
                $hasCreated = true;
                if ((int) $event->get('roleId') !== $id || (bool) $event->get('isSystem', false) !== (bool) $row['is_system']) {
                    throw new UnsupportedEventSequence($key, 'role.created の roleId・isSystem が行と違う(updated では直せない)');
                }
            }
        }

        $state = null;
        $last = $ctx->afterLast($aggregate);
        if (! $hasCreated) {
            $createdAt = Norm::str($row['created_at'] ?? null);
            $slot = $ctx->beforeFirst($aggregate, $createdAt) ?? $ctx->head($createdAt);
            $createdSlot = $ctx->emit(self::TABLE, $slot, 'role.created', $aggregate, [
                'roleId' => $id,
                'code' => $code,
                'name' => $want['name'],
                'description' => $want['description'],
                'actorUserId' => null,
                'isSystem' => (bool) $row['is_system'],
            ], $createdAt);
            $ctx->registerRef('role:'.$id, $createdSlot);
            $state = ['status' => 'active'] + $want;
            $last ??= $createdSlot;
        }

        foreach ($keyEvents as $event) {
            $payload = $event->payload;
            if ($event->eventClass === 'role.created') {
                $state = ['name' => (string) $payload['name'], 'description' => Norm::str($payload['description'] ?? null), 'status' => 'active'];
            } else {
                $state = ['name' => (string) $payload['name'], 'description' => Norm::str($payload['description'] ?? null), 'status' => (string) $payload['status']] + ($state ?? []);
            }
        }

        $differences = [];
        foreach ($want as $column => $value) {
            if (! array_key_exists($column, $state ?? []) || $state[$column] !== $value) {
                $differences[] = $column;
            }
        }
        if ($keyEvents !== [] && ($differences !== [] || ! $hasCreated)) {
            $text = ReconcileContext::describe($state ?? [], $want, $differences);
            // created が無い場合は role.created を導出している(同じ照合で置く)。
            $derived = $differences === [] ? [] : ['role.updated'];
            if (! $hasCreated) {
                array_unshift($derived, 'role.created');
            }
            $ctx->mismatch(self::TABLE, $key, $hasCreated ? '最終状態が違う: '.$text : 'role.created が無い'.($text === '' ? '' : '、最終状態が違う: '.$text), $derived);
        }
        if ($differences === []) {
            return;
        }

        $ctx->emit(self::TABLE, $last, 'role.updated', $aggregate, [
            'roleId' => $id,
            'name' => $want['name'],
            'description' => $want['description'],
            'status' => $want['status'],
            'actorUserId' => null,
        ]);
    }
}
