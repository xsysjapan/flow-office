<?php

namespace App\Domain\EventSourcing\Rebuild\Reconcile;

use App\Domain\UserManagement\Support\UserManagementStreamId;

/**
 * memberships の照合(論点19 決定1)。集約UUIDは UserManagementStreamId::for('user-membership', userId)。
 *
 * イベントによる最終状態: (利用者, グループ)ごとに membership.added(kind・isPrimary・操作者)、membership.primary_changed
 * (isPrimary と kind=primary/member)、membership.removed(所属なし)を順に畳み込んだもの。
 * - 行があり、(利用者, グループ)のイベントが1件も無い: membership.added を導出する。集約にイベントが無ければ、利用者・グループ・
 *   作成者の作成イベントのうち最も後ろの直後(無ければ列の先頭)。イベントがあれば最初のイベントの直前(ただし依存先の作成より前には置かない)。
 * - 行があり、最終状態が違う(除去済み・kind/isPrimary が違う): 最後のイベントの直後に membership.added(行の値)を導出する。
 *   操作者(created_by)だけが違う場合は差にしない(旧版の正規化が行を直接書いた意図した差。導出も報告もしない。論点19 決定10-7)。
 * - 行が無いのに最終状態では所属している: 最後の直後に membership.removed(操作者 null)を導出する。
 * added の操作者は行の created_by。
 */
final class MembershipReconciler
{
    public const TABLE = 'memberships';

    private const CLASSES = ['membership.added', 'membership.removed', 'membership.primary_changed'];

    public function reconcile(ReconcileContext $ctx): void
    {
        /** @var array<string, array<string, array<string, mixed>>> $rowsByUser */
        $rowsByUser = [];
        foreach ($ctx->current->memberships as $row) {
            $row = Norm::row($row);
            $rowsByUser[(string) $row['user_id']][(string) $row['group_id']] = $row;
        }
        $userIds = array_keys($rowsByUser);
        foreach ($ctx->rows() as $event) {
            if (in_array($event->eventClass, self::CLASSES, true)) {
                $userIds[] = (string) $event->get('userId');
            }
        }
        $userIds = array_values(array_unique($userIds));
        sort($userIds);

        foreach ($userIds as $userId) {
            $this->user($ctx, $userId, $rowsByUser[$userId] ?? []);
        }
    }

    /** @param  array<string, array<string, mixed>>  $rows  group_id => 行 */
    private function user(ReconcileContext $ctx, string $userId, array $rows): void
    {
        $aggregate = UserManagementStreamId::for('user-membership', $userId);
        $events = $ctx->rowsOfClasses($aggregate, self::CLASSES);

        /** @var array<string, array{present: bool, kind: ?string, primary: bool, created_by: ?string}> $state */
        $state = [];
        foreach ($events as $event) {
            $groupId = (string) $event->get('groupId');
            if ($event->eventClass === 'membership.added') {
                $state[$groupId] = ['present' => true, 'kind' => (string) $event->get('membershipKind'), 'primary' => (bool) $event->get('isPrimary'), 'created_by' => Norm::str($event->get('actorUserId'))];
            } elseif ($event->eventClass === 'membership.removed') {
                $state[$groupId] = ['present' => false, 'kind' => null, 'primary' => false, 'created_by' => null];
            } else {
                $previous = $state[$groupId] ?? ['present' => false, 'kind' => null, 'primary' => false, 'created_by' => null];
                $isPrimary = (bool) $event->get('isPrimary');
                $state[$groupId] = ['kind' => $isPrimary ? 'primary' : 'member', 'primary' => $isPrimary] + $previous;
            }
        }

        $groupIds = array_values(array_unique(array_merge(array_keys($rows), array_keys($state))));
        sort($groupIds);
        foreach ($groupIds as $groupId) {
            $key = "membership {$userId}/{$groupId}";
            $ctx->guard($key, fn () => $this->one($ctx, $aggregate, $userId, $groupId, $rows[$groupId] ?? null, $state[$groupId] ?? null, $key));
        }
    }

    /**
     * @param  array<string, mixed>|null  $row
     * @param  array{present: bool, kind: ?string, primary: bool, created_by: ?string}|null  $final
     */
    private function one(ReconcileContext $ctx, string $aggregate, string $userId, string $groupId, ?array $row, ?array $final, string $key): void
    {
        if ($row === null) {
            if ($final !== null && $final['present']) {
                $ctx->mismatch(self::TABLE, $key, '最終状態が違う: '.ReconcileContext::describe(
                    ['membership_kind' => $final['kind'], 'is_primary' => $final['primary'], 'created_by' => $final['created_by']],
                    [],
                    ['membership_kind', 'is_primary', 'created_by'],
                ), ['membership.removed']);
                $ctx->emit(self::TABLE, $ctx->afterLast($aggregate), 'membership.removed', $aggregate, [
                    'userId' => $userId, 'groupId' => $groupId, 'actorUserId' => null,
                ]);
            }

            return;
        }

        $kind = (string) $row['membership_kind'];
        $primary = (bool) $row['is_primary'];
        $createdBy = Norm::str($row['created_by'] ?? null);
        // 在籍・kind・isPrimary が一致していれば差にしない。操作者(created_by)だけが違う場合は、旧版の正規化が
        // 行を直接書いた意図した差(論点19 決定10-7)として、導出も報告もしない。
        if ($final !== null && $final['present'] && $final['kind'] === $kind && $final['primary'] === $primary) {
            return;
        }

        $createdAt = Norm::str($row['created_at'] ?? null);
        $eventAt = null;
        if ($final !== null) {
            $ctx->mismatch(self::TABLE, $key, '最終状態が違う: '.ReconcileContext::describe(
                ['membership_kind' => $final['kind'], 'is_primary' => $final['primary']],
                ['membership_kind' => $kind, 'is_primary' => $primary],
                ['membership_kind', 'is_primary'],
            ), ['membership.added']);
            $slot = $ctx->afterLast($aggregate);
        } else {
            $eventAt = $createdAt;
            $dep = $ctx->latest($ctx->ref('user:'.$userId), $ctx->ref('group:'.$groupId), $createdBy !== null ? $ctx->ref('user:'.$createdBy) : null);
            $slot = $ctx->beforeFirst($aggregate, $createdAt);
            $slot = $slot !== null
                ? $ctx->notBefore($slot, $dep)
                : ($dep !== null ? $ctx->afterRef($dep, $createdAt) : $ctx->head($createdAt));
        }

        $ctx->emit(self::TABLE, $slot, 'membership.added', $aggregate, [
            'userId' => $userId,
            'groupId' => $groupId,
            'membershipKind' => $kind,
            'isPrimary' => $primary,
            'actorUserId' => $createdBy,
        ], $eventAt);
    }
}
