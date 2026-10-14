<?php

namespace App\Domain\EventSourcing\Rebuild\Reconcile;

use App\Domain\EventSourcing\Rebuild\StoredEventRow;
use App\Domain\EventSourcing\Rebuild\UnsupportedEventSequence;

/**
 * users の照合(論点19 決定1)。集約UUIDは行の id。
 *
 * 作成系のイベント(user.created_manually・user.onboarded_as_admin・user.created_from_sso_login・user.synced_from_ms365・
 * user.migrated_from_legacy、external_hr.import_applied の rows[].user_id)が無い利用者だけ、行の値から既存の合成イベント
 * user.migrated_from_legacy を導出する(最終状態の比較はしない)。置く位置は、その利用者の集約の最初のイベントの直前
 * (イベントが無ければ列の先頭)、発生日時は置き場所の前のイベントの値(列の先頭なら行の created_at)。
 * attributes はパスワードを含まない行の値(null の列は省く。自動付与フラグ・created_at を含む)。
 *
 * 属性の補完(論点19 決定10-3): 作成系のイベントがある利用者について、属性を設定するイベントがある項目
 * (usage_start_date・hire_date・termination_date・timezone・paid_leave_auto_grant_enabled・special_leave_auto_grant_enabled)の
 * イベントによる最終状態(作成系イベントの値 → 設定イベントの値の畳み込み。UserProjector と同じ規則)を現在の行と比べ、違う項目ごとに
 * 最後のイベントの直後へ設定イベントを導出する(操作者 null)。timezone の設定イベントは user.profile_updated のみ。
 * source_type は設定イベントが無いため対象外。作成系のイベントが無く合成イベントを導出した利用者は(行の値が入るため)対象外。
 */
final class UserReconciler
{
    public const TABLE = 'users';

    /** 作成系のイベント(集約UUIDが利用者のid)。 */
    public const CREATION_CLASSES = [
        'user.created_manually',
        'user.onboarded_as_admin',
        'user.created_from_sso_login',
        'user.synced_from_ms365',
        'user.migrated_from_legacy',
    ];

    public function reconcile(ReconcileContext $ctx): void
    {
        $synthesized = [];
        foreach ($ctx->current->users as $row) {
            $row = Norm::row($row);
            $id = (string) $row['id'];
            if ($ctx->ref('user:'.$id) !== null) {
                continue;
            }
            $ctx->guard('user '.$id, function () use ($ctx, $row, $id, &$synthesized): void {
                $createdAt = Norm::str($row['created_at'] ?? null);
                $slot = $ctx->beforeFirst($id, $createdAt) ?? $ctx->head($createdAt);
                $created = $ctx->emit(self::TABLE, $slot, 'user.migrated_from_legacy', $id, ['attributes' => $this->attributes($row)], $createdAt);
                $ctx->registerRef('user:'.$id, $created);
                $synthesized[$id] = true;
            });
        }

        $this->reconcileAttributes($ctx, $synthesized);
    }

    /** 属性の設定イベントがある項目と、設定イベント・payload の対応。 */
    private const ATTRIBUTE_COLUMNS = ['usage_start_date', 'hire_date', 'termination_date', 'timezone', 'paid_leave_auto_grant_enabled', 'special_leave_auto_grant_enabled'];

    /**
     * @param  array<string, bool>  $synthesized  合成イベントを導出した利用者のid
     */
    private function reconcileAttributes(ReconcileContext $ctx, array $synthesized): void
    {
        $defaultTimezone = $ctx->current->defaultTimezone;
        $initial = ['usage_start_date' => null, 'hire_date' => null, 'termination_date' => null, 'timezone' => null,
            'paid_leave_auto_grant_enabled' => true, 'special_leave_auto_grant_enabled' => true];

        /** @var array<string, array<string, mixed>> $states */
        $states = [];
        /** @var array<string, StoredEventRow> $lastImport */
        $lastImport = [];
        foreach ($ctx->rows() as $row) {
            $id = $row->aggregateUuid;
            $date = substr($row->occurredAt(), 0, 10);
            if ($row->eventClass === 'external_hr.import_applied') {
                foreach ((array) $row->get('rows', []) as $importRow) {
                    $userId = isset($importRow['user_id']) ? (string) $importRow['user_id'] : null;
                    if ($userId === null) {
                        continue;
                    }
                    if (! isset($states[$userId])) {
                        $states[$userId] = ['usage_start_date' => $date, 'timezone' => $defaultTimezone] + $initial;
                    }
                    $lastImport[$userId] = $row;
                }

                continue;
            }
            if ($id === null) {
                continue;
            }
            $payload = $row->payload;
            switch ($row->eventClass) {
                case 'user.created_manually':
                    $states[$id] ??= $initial;
                    $this->applyAttributes($states[$id], (array) ($payload['attributes'] ?? []));
                    $states[$id]['timezone'] = $defaultTimezone;
                    $states[$id]['usage_start_date'] = $date;
                    break;
                case 'user.onboarded_as_admin':
                case 'user.created_from_sso_login':
                    $states[$id] ??= $initial;
                    $states[$id]['timezone'] = $defaultTimezone;
                    $states[$id]['usage_start_date'] = $date;
                    break;
                case 'user.synced_from_ms365':
                    $states[$id] ??= $initial;
                    $states[$id]['timezone'] ??= $defaultTimezone;
                    $states[$id]['usage_start_date'] ??= $date;
                    break;
                case 'user.migrated_from_legacy':
                    $states[$id] ??= $initial;
                    $this->applyAttributes($states[$id], (array) ($payload['attributes'] ?? []));
                    break;
                case 'user.hire_date_set':
                    $states[$id] ??= $initial;
                    $states[$id]['hire_date'] = Norm::date($payload['hireDate'] ?? null);
                    break;
                case 'user.termination_date_set':
                    $states[$id] ??= $initial;
                    $states[$id]['termination_date'] = Norm::date($payload['terminationDate'] ?? null);
                    break;
                case 'user.usage_start_date_set':
                    $states[$id] ??= $initial;
                    $states[$id]['usage_start_date'] = Norm::date($payload['usageStartDate'] ?? null);
                    break;
                case 'user.paid_leave_auto_grant_enabled_set':
                    $states[$id] ??= $initial;
                    $states[$id]['paid_leave_auto_grant_enabled'] = (bool) ($payload['enabled'] ?? false);
                    break;
                case 'user.special_leave_auto_grant_enabled_set':
                    $states[$id] ??= $initial;
                    $states[$id]['special_leave_auto_grant_enabled'] = (bool) ($payload['enabled'] ?? false);
                    break;
                case 'user.profile_updated':
                    $states[$id] ??= $initial;
                    $this->applyAttributes($states[$id], (array) ($payload['after'] ?? []));
                    break;
            }
        }

        foreach ($ctx->current->users as $row) {
            $row = Norm::row($row);
            $id = (string) $row['id'];
            if (isset($synthesized[$id]) || ! isset($states[$id])) {
                continue;
            }
            $ctx->guard('user '.$id, fn () => $this->reconcileOne($ctx, $row, $states[$id], $lastImport[$id] ?? null));
        }
    }

    /**
     * 作成系イベント・設定イベントの値(attributes・after)のうち、補完の対象の項目だけを状態へ反映する。
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $values
     */
    private function applyAttributes(array &$state, array $values): void
    {
        foreach (self::ATTRIBUTE_COLUMNS as $column) {
            if (! array_key_exists($column, $values)) {
                continue;
            }
            $value = $values[$column];
            $state[$column] = match ($column) {
                'usage_start_date', 'hire_date', 'termination_date' => Norm::date($value),
                'timezone' => Norm::str($value),
                default => $value === null ? null : (bool) $value,
            };
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $state
     */
    private function reconcileOne(ReconcileContext $ctx, array $row, array $state, ?StoredEventRow $lastImport): void
    {
        $id = (string) $row['id'];
        $key = 'user '.$id;
        $want = [
            'usage_start_date' => Norm::date($row['usage_start_date'] ?? null),
            'hire_date' => Norm::date($row['hire_date'] ?? null),
            'termination_date' => Norm::date($row['termination_date'] ?? null),
            'timezone' => Norm::str($row['timezone'] ?? null),
            'paid_leave_auto_grant_enabled' => ($row['paid_leave_auto_grant_enabled'] ?? null) === null ? null : (bool) $row['paid_leave_auto_grant_enabled'],
            'special_leave_auto_grant_enabled' => ($row['special_leave_auto_grant_enabled'] ?? null) === null ? null : (bool) $row['special_leave_auto_grant_enabled'],
        ];

        $differences = [];
        foreach ($want as $column => $value) {
            // 既定のタイムゾーンが分からず状態が不明な timezone、行が null の列(設定イベントで null にできない)は比べない。
            if ($column === 'timezone' && $state[$column] === null) {
                continue;
            }
            if ($state[$column] === $value) {
                continue;
            }
            if ($value === null && $column !== 'termination_date') {
                throw new UnsupportedEventSequence($key, "{$column} が行では null だが、イベントでは値が設定されている(設定イベントでは null にできない)");
            }
            $differences[] = $column;
        }
        if ($differences === []) {
            return;
        }

        $last = $ctx->latest($ctx->afterLast($id), $lastImport !== null ? Slot::ofRow($lastImport) : null);
        if ($last === null) {
            throw new UnsupportedEventSequence($key, '最後のイベントが無く、設定イベントを置く位置を決められない');
        }
        $ctx->mismatch(self::TABLE, $key, '属性の最終状態が違う: '.implode(', ', $differences));

        foreach ($differences as $column) {
            $last = match ($column) {
                'usage_start_date' => $ctx->emit(self::TABLE, $last, 'user.usage_start_date_set', $id, ['usageStartDate' => $want[$column], 'changedByUserId' => null]),
                'hire_date' => $ctx->emit(self::TABLE, $last, 'user.hire_date_set', $id, ['hireDate' => $want[$column], 'changedByUserId' => null]),
                'termination_date' => $ctx->emit(self::TABLE, $last, 'user.termination_date_set', $id, ['terminationDate' => $want[$column], 'changedByUserId' => null]),
                'timezone' => $ctx->emit(self::TABLE, $last, 'user.profile_updated', $id, ['before' => ['timezone' => $state['timezone']], 'after' => ['timezone' => $want[$column]], 'changedByUserId' => null]),
                'paid_leave_auto_grant_enabled' => $ctx->emit(self::TABLE, $last, 'user.paid_leave_auto_grant_enabled_set', $id, ['enabled' => $want[$column], 'changedByUserId' => null]),
                'special_leave_auto_grant_enabled' => $ctx->emit(self::TABLE, $last, 'user.special_leave_auto_grant_enabled_set', $id, ['enabled' => $want[$column], 'changedByUserId' => null]),
            };
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function attributes(array $row): array
    {
        $attributes = [];
        foreach (['entra_user_id', 'name', 'email', 'employee_number', 'account_status', 'source_type', 'department', 'job_title', 'employment_status', 'timezone'] as $column) {
            if (($row[$column] ?? null) !== null) {
                $attributes[$column] = (string) $row[$column];
            }
        }
        foreach (['hire_date', 'termination_date', 'usage_start_date'] as $column) {
            if (Norm::date($row[$column] ?? null) !== null) {
                $attributes[$column] = Norm::date($row[$column]);
            }
        }
        foreach (['paid_leave_auto_grant_enabled', 'special_leave_auto_grant_enabled'] as $column) {
            if (($row[$column] ?? null) !== null) {
                $attributes[$column] = (bool) $row[$column];
            }
        }
        if (($row['created_at'] ?? null) !== null) {
            $attributes['created_at'] = (string) $row['created_at'];
        }
        if (($row['last_login_at'] ?? null) !== null) {
            $attributes['last_login_at'] = (string) $row['last_login_at'];
        }

        return $attributes;
    }
}
