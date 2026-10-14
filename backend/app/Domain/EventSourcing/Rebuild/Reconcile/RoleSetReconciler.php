<?php

namespace App\Domain\EventSourcing\Rebuild\Reconcile;

/**
 * permission_role・role_features の照合(論点19 決定1)。ロールごとの集合の全置換イベント
 * (role.permissions_changed・role.features_changed)で表す。集約はロール(uuid5(URL, 'role:'.code))。
 *
 * イベントによる最終状態: ロールの最後の全置換イベントの ID の集合(イベントが無ければ空)。
 * - 行に値があり、そのイベントが1件も無い: ロールの作成イベント(既存または導出)の直後に導出する。ロールの作成より前に
 *   置くと外部キーで再生できないため、「集約の最初のイベントの直前」ではなく作成の直後にする(最初のイベントが作成の場合も同じ)。
 * - 最終状態が行と違う: 最後のイベントの直後に、行の集合の全置換を導出する。
 * 操作者は null。
 */
final class RoleSetReconciler
{
    /**
     * @param  string  $table  permission_role または role_features
     * @param  string  $idColumn  行のIDの列(permission_id・feature_id)
     * @param  string  $eventClass  role.permissions_changed または role.features_changed
     * @param  string  $payloadKey  permissionIds または featureIds
     */
    public function __construct(
        private readonly string $table,
        private readonly string $idColumn,
        private readonly string $eventClass,
        private readonly string $payloadKey,
    ) {}

    public static function permissions(): self
    {
        return new self('permission_role', 'permission_id', 'role.permissions_changed', 'permissionIds');
    }

    public static function features(): self
    {
        return new self('role_features', 'feature_id', 'role.features_changed', 'featureIds');
    }

    public function reconcile(ReconcileContext $ctx): void
    {
        $rows = $this->table === 'permission_role' ? $ctx->current->permissionRole : $ctx->current->roleFeatures;
        $idsByRole = [];
        $createdAtByRole = [];
        foreach ($rows as $row) {
            $row = Norm::row($row);
            $role = (int) $row['role_id'];
            $idsByRole[$role][] = (int) $row[$this->idColumn];
            $createdAt = Norm::str($row['created_at'] ?? null);
            if ($createdAt !== null && ($createdAtByRole[$role] ?? $createdAt) >= $createdAt) {
                $createdAtByRole[$role] = $createdAt;
            }
        }

        foreach ($ctx->current->roles as $role) {
            $role = Norm::row($role);
            $roleId = (int) $role['id'];
            $ids = array_values(array_unique($idsByRole[$roleId] ?? []));
            sort($ids);
            $ctx->guard($this->table.' role '.$role['code'], fn () => $this->one($ctx, $roleId, (string) $role['code'], $ids, $createdAtByRole[$roleId] ?? null));
        }
    }

    /** @param  list<int>  $ids */
    private function one(ReconcileContext $ctx, int $roleId, string $code, array $ids, ?string $rowCreatedAt): void
    {
        $key = "{$this->table} role {$code}";
        $aggregate = RoleReconciler::aggregateOf($code);
        $keyEvents = $ctx->rowsOfClasses($aggregate, [$this->eventClass]);

        $payload = fn () => ['roleId' => $roleId, $this->payloadKey => $ids, 'actorUserId' => null];

        if ($keyEvents === []) {
            if ($ids === []) {
                return;
            }
            $ref = $ctx->ref('role:'.$roleId);
            $slot = $ref !== null ? $ctx->afterRef($ref, $rowCreatedAt) : ($ctx->beforeFirst($aggregate, $rowCreatedAt) ?? $ctx->head($rowCreatedAt));
            $ctx->emit($this->table, $slot, $this->eventClass, $aggregate, $payload());

            return;
        }

        $lastEvent = $keyEvents[count($keyEvents) - 1];
        $current = array_values(array_unique(array_map('intval', (array) $lastEvent->get($this->payloadKey, []))));
        sort($current);
        if ($current === $ids) {
            return;
        }

        $ctx->mismatch($this->table, $key, '最後の '.$this->eventClass.' の '.$this->payloadKey.' ['.implode(',', $current).'] が行 ['.implode(',', $ids).'] と違う', [$this->eventClass]);
        $ctx->emit($this->table, $ctx->afterLast($aggregate), $this->eventClass, $aggregate, $payload());
    }
}
