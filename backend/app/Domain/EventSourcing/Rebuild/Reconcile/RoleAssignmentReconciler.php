<?php

namespace App\Domain\EventSourcing\Rebuild\Reconcile;

use App\Domain\EventSourcing\Rebuild\UnsupportedEventSequence;

/**
 * role_assignments の照合(論点19 決定1)。集約UUIDは行の id。
 *
 * イベントによる最終状態: role_assignment.created の値を、role_assignment.updated(範囲・期間)・role_assignment.removed(status)で
 * 順に上書きしたもの。
 * - created が無い: 行の値から role_assignment.created を導出する。集約にイベントが無ければ、ロール・スコープのグループ・
 *   割当者の作成イベントのうち最も後ろの直後(無ければ列の先頭)。イベントがあれば最初のイベントの直前。操作者は行の assigned_by。
 * - 導出できない違い(created があるのに最終状態が違う・status が active でない行)は、差を埋めるイベントを決められないため
 *   扱えない並びとして止める(updated・removed は操作者を必須とする)。
 */
final class RoleAssignmentReconciler
{
    public const TABLE = 'role_assignments';

    public function reconcile(ReconcileContext $ctx): void
    {
        foreach ($ctx->current->roleAssignments as $row) {
            $ctx->guard('role_assignment '.$row['id'], fn () => $this->one($ctx, Norm::row($row)));
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function want(array $row): array
    {
        return [
            'subject_type' => (string) $row['subject_type'],
            'subject_id' => (string) $row['subject_id'],
            'role_id' => (int) $row['role_id'],
            'scope_type' => (string) $row['scope_type'],
            'scope_group_id' => Norm::str($row['scope_group_id'] ?? null),
            'include_descendants' => (bool) $row['include_descendants'],
            'starts_at' => Norm::rowDateTime($row['starts_at'] ?? null),
            'ends_at' => Norm::rowDateTime($row['ends_at'] ?? null),
            'status' => (string) $row['status'],
            'assigned_by' => Norm::str($row['assigned_by'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function fromCreated(array $payload): array
    {
        return [
            'subject_type' => (string) $payload['subjectType'],
            'subject_id' => (string) $payload['subjectId'],
            'role_id' => (int) $payload['roleId'],
            'scope_type' => (string) $payload['scopeType'],
            'scope_group_id' => Norm::str($payload['scopeGroupId'] ?? null),
            'include_descendants' => (bool) $payload['includeDescendants'],
            'starts_at' => Norm::eventDateTime($payload['startsAt'] ?? null),
            'ends_at' => Norm::eventDateTime($payload['endsAt'] ?? null),
            'status' => 'active',
            'assigned_by' => Norm::str($payload['actorUserId'] ?? null),
        ];
    }

    /** @param  array<string, mixed>  $row */
    private function one(ReconcileContext $ctx, array $row): void
    {
        $id = (string) $row['id'];
        $key = "role_assignment {$id}";
        $want = $this->want($row);
        $events = $ctx->rowsOf($id);

        $state = null;
        $hasCreated = false;
        foreach ($events as $event) {
            if ($event->eventClass === 'role_assignment.created') {
                $hasCreated = true;
            }
        }

        if (! $hasCreated) {
            if ($want['status'] !== 'active') {
                throw new UnsupportedEventSequence($key, "イベントが無く status={$want['status']} の行は、role_assignment.removed の操作者を決められない");
            }
            $createdAt = Norm::str($row['created_at'] ?? null);
            $dep = $ctx->latest(
                $ctx->ref('role:'.$want['role_id']),
                $want['scope_group_id'] !== null ? $ctx->ref('group:'.$want['scope_group_id']) : null,
                $want['assigned_by'] !== null ? $ctx->ref('user:'.$want['assigned_by']) : null,
            );
            $slot = $ctx->beforeFirst($id, $createdAt);
            $slot = $slot !== null
                ? $ctx->notBefore($slot, $dep)
                : ($dep !== null ? $ctx->afterRef($dep, $createdAt) : $ctx->head($createdAt));
            $ctx->emit(self::TABLE, $slot, 'role_assignment.created', $id, [
                'subjectType' => $want['subject_type'],
                'subjectId' => $want['subject_id'],
                'roleId' => $want['role_id'],
                'scopeType' => $want['scope_type'],
                'scopeGroupId' => $want['scope_group_id'],
                'includeDescendants' => $want['include_descendants'],
                'startsAt' => Norm::payloadDateTime($row['starts_at'] ?? null),
                'endsAt' => Norm::payloadDateTime($row['ends_at'] ?? null),
                'actorUserId' => $want['assigned_by'],
            ]);
            $state = ['status' => 'active'] + $want;
        }

        foreach ($events as $event) {
            $payload = $event->payload;
            if ($event->eventClass === 'role_assignment.created') {
                $state = $this->fromCreated($payload);
            } elseif ($event->eventClass === 'role_assignment.updated') {
                $state = [
                    'scope_type' => (string) $payload['scopeType'],
                    'scope_group_id' => Norm::str($payload['scopeGroupId'] ?? null),
                    'include_descendants' => (bool) $payload['includeDescendants'],
                    'starts_at' => Norm::eventDateTime($payload['startsAt'] ?? null),
                    'ends_at' => Norm::eventDateTime($payload['endsAt'] ?? null),
                ] + ($state ?? []);
            } elseif ($event->eventClass === 'role_assignment.removed') {
                $state = ['status' => 'removed'] + ($state ?? []);
            }
        }

        $differences = [];
        foreach ($want as $column => $value) {
            if (! array_key_exists($column, $state ?? []) || $state[$column] !== $value) {
                $differences[] = $column;
            }
        }
        if ($differences === []) {
            return;
        }

        $ctx->mismatch(self::TABLE, $key, '最終状態が違う: '.implode(', ', $differences));
        throw new UnsupportedEventSequence($key, 'イベントの最終状態が行と違い('.implode(', ', $differences).')、差を埋めるイベントを決められない');
    }
}
