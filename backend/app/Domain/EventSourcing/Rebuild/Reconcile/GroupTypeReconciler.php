<?php

namespace App\Domain\EventSourcing\Rebuild\Reconcile;

use App\Domain\EventSourcing\Rebuild\UnsupportedEventSequence;
use Ramsey\Uuid\Uuid;

/**
 * group_types の照合(論点19 決定1)。集約UUIDは uuid5(URL, 'group-type:'.code)。
 *
 * イベントによる最終状態: group_type.created の値を、group_type.updated の値で順に上書きしたもの。
 * - created が無い: 行の値から group_type.created を導出する(isSystem は行の値。status は作成では active になる)。
 * - 最終状態が行と違う: 最後のイベントの直後に、行の値の group_type.updated を導出する(status が active でなければ作成の直後)。
 * 操作者は null(行に作成者の列が無い)。
 */
final class GroupTypeReconciler
{
    public const TABLE = 'group_types';

    public function reconcile(ReconcileContext $ctx): void
    {
        foreach ($ctx->current->groupTypes as $row) {
            $ctx->guard('group_type '.$row['code'], fn () => $this->one($ctx, Norm::row($row)));
        }
    }

    /** @param  array<string, mixed>  $row */
    private function one(ReconcileContext $ctx, array $row): void
    {
        $id = (int) $row['id'];
        $code = (string) $row['code'];
        $key = "group_type {$code}";
        $aggregate = Uuid::uuid5(Uuid::NAMESPACE_URL, 'group-type:'.$code)->toString();
        $want = $this->state($row);
        $keyEvents = $ctx->rowsOfClasses($aggregate, ['group_type.created', 'group_type.updated']);

        $state = null;
        $last = $ctx->afterLast($aggregate);
        $createdSlot = null;

        $hasCreated = false;
        foreach ($keyEvents as $event) {
            if ($event->eventClass === 'group_type.created') {
                $hasCreated = true;
                if ((int) $event->get('groupTypeId') !== $id || (bool) $event->get('isSystem', false) !== (bool) $row['is_system']) {
                    throw new UnsupportedEventSequence($key, 'group_type.created の groupTypeId・isSystem が行と違う(updated では直せない)');
                }
            }
        }

        if (! $hasCreated) {
            $slot = $ctx->beforeFirst($aggregate, Norm::str($row['created_at'] ?? null))
                ?? $ctx->head(Norm::str($row['created_at'] ?? null));
            $createdSlot = $ctx->emit(self::TABLE, $slot, 'group_type.created', $aggregate, [
                'groupTypeId' => $id,
                'code' => $code,
                'name' => $want['name'],
                'displayOrder' => $want['display_order'],
                'membershipLimitType' => $want['membership_limit_type'],
                'maxMembershipsPerUser' => $want['max_memberships_per_user'],
                'primaryMembershipRequired' => $want['primary_membership_required'],
                'maxPrimaryMemberships' => $want['max_primary_memberships'],
                'actorUserId' => null,
                'isSystem' => (bool) $row['is_system'],
            ], Norm::str($row['created_at'] ?? null));
            $ctx->registerRef('group_type:'.$id, $createdSlot);
            $state = ['status' => 'active'] + $want;
            $last ??= $createdSlot;
        }

        foreach ($keyEvents as $event) {
            if ($event->eventClass === 'group_type.created') {
                $state = $this->fromCreated($event->payload);
            } elseif ($event->eventClass === 'group_type.updated') {
                $state = $this->fromUpdated($event->payload, $state ?? []);
            }
        }

        $differences = $this->differences($state ?? [], $want);
        if ($keyEvents !== [] && ($differences !== [] || ! $hasCreated)) {
            $ctx->mismatch(self::TABLE, $key, $hasCreated ? '最終状態が違う: '.implode(', ', $differences) : 'group_type.created が無い'.($differences === [] ? '' : '、最終状態が違う: '.implode(', ', $differences)));
        }
        if ($differences === []) {
            return;
        }

        $ctx->emit(self::TABLE, $last, 'group_type.updated', $aggregate, [
            'groupTypeId' => $id,
            'name' => $want['name'],
            'displayOrder' => $want['display_order'],
            'status' => $want['status'],
            'membershipLimitType' => $want['membership_limit_type'],
            'maxMembershipsPerUser' => $want['max_memberships_per_user'],
            'primaryMembershipRequired' => $want['primary_membership_required'],
            'maxPrimaryMemberships' => $want['max_primary_memberships'],
            'actorUserId' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function state(array $row): array
    {
        return [
            'name' => (string) $row['name'],
            'display_order' => (int) $row['display_order'],
            'status' => (string) $row['status'],
            'membership_limit_type' => (string) $row['membership_limit_type'],
            'max_memberships_per_user' => Norm::int($row['max_memberships_per_user']),
            'primary_membership_required' => (bool) $row['primary_membership_required'],
            'max_primary_memberships' => Norm::int($row['max_primary_memberships']),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function fromCreated(array $payload): array
    {
        return [
            'name' => (string) $payload['name'],
            'display_order' => (int) $payload['displayOrder'],
            'status' => 'active',
            'membership_limit_type' => (string) $payload['membershipLimitType'],
            'max_memberships_per_user' => Norm::int($payload['maxMembershipsPerUser'] ?? null),
            'primary_membership_required' => (bool) $payload['primaryMembershipRequired'],
            'max_primary_memberships' => Norm::int($payload['maxPrimaryMemberships'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function fromUpdated(array $payload, array $state): array
    {
        return [
            'name' => (string) $payload['name'],
            'display_order' => (int) $payload['displayOrder'],
            'status' => (string) $payload['status'],
            'membership_limit_type' => (string) $payload['membershipLimitType'],
            'max_memberships_per_user' => Norm::int($payload['maxMembershipsPerUser'] ?? null),
            'primary_membership_required' => (bool) $payload['primaryMembershipRequired'],
            'max_primary_memberships' => Norm::int($payload['maxPrimaryMemberships'] ?? null),
        ] + $state;
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $want
     * @return list<string> 違う列名
     */
    private function differences(array $state, array $want): array
    {
        $columns = [];
        foreach ($want as $column => $value) {
            if (! array_key_exists($column, $state) || $state[$column] !== $value) {
                $columns[] = $column;
            }
        }

        return $columns;
    }
}
