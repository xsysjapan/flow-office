<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * `paid_leave_usages`テーブルの新`PaidLeaveAccount`ドメイン側の行を扱う姉妹モデル
 * (docs/changesets/20260906-paid-leave-domain-redesign/spec.md 論点7)。物理テーブルは
 * `App\Models\PaidLeaveUsage`(旧ドメイン専用)と同一だが、旧ドメイン側の素朴な
 * `where('user_id', ...)`等のクエリが新ドメインの行(`usage_id`列が設定されている行)を
 * 誤って拾わないよう、`PaidLeaveUsage`側にデフォルトスコープ(`usage_id IS NULL`)を
 * 敷いた上で、新ドメイン側からの読み書きはこちらのモデル(`usage_id IS NOT NULL`)を
 * 使う。カラム自体はPhase 3で追加済みのため新規migrationは不要。
 */
#[Fillable(['usage_id', 'user_id', 'attendance_day_id', 'paid_leave_grant_id', 'paid_leave_request_id', 'used_on', 'used_days', 'usage_type', 'confirmed', 'cancelled'])]
class PaidLeaveAccountUsage extends Model
{
    protected $table = 'paid_leave_usages';

    protected static function booted(): void
    {
        static::addGlobalScope('newPaidLeaveAccountDomain', function ($query) {
            $query->whereNotNull('usage_id');
        });
    }

    protected function casts(): array
    {
        return [
            'used_on' => 'date',
            'used_days' => 'decimal:1',
            'confirmed' => 'boolean',
            'cancelled' => 'boolean',
        ];
    }
}
