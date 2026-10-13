<?php

namespace App\Domain\EventSourcing\Rebuild\Reconcile;

/**
 * users の照合(論点19 決定1)。集約UUIDは行の id。
 *
 * 作成系のイベント(user.created_manually・user.onboarded_as_admin・user.created_from_sso_login・user.synced_from_ms365・
 * user.migrated_from_legacy、external_hr.import_applied の rows[].user_id)が無い利用者だけ、行の値から既存の合成イベント
 * user.migrated_from_legacy を導出する(最終状態の比較はしない)。置く位置は、その利用者の集約の最初のイベントの直前
 * (イベントが無ければ列の先頭)、発生日時は置き場所の前のイベントの値(列の先頭なら行の created_at)。
 * attributes はパスワードを含まない行の値(null の列は省く。自動付与フラグ・created_at を含む)。
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
        foreach ($ctx->current->users as $row) {
            $row = Norm::row($row);
            $id = (string) $row['id'];
            if ($ctx->ref('user:'.$id) !== null) {
                continue;
            }
            $ctx->guard('user '.$id, function () use ($ctx, $row, $id): void {
                $createdAt = Norm::str($row['created_at'] ?? null);
                $slot = $ctx->beforeFirst($id, $createdAt) ?? $ctx->head($createdAt);
                $created = $ctx->emit(self::TABLE, $slot, 'user.migrated_from_legacy', $id, ['attributes' => $this->attributes($row)], $createdAt);
                $ctx->registerRef('user:'.$id, $created);
            });
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
