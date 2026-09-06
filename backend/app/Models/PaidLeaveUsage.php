<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 有給消化 (docs/03-architecture.md 3.3: 勤怠の正の一つ)。有効期限が近い付与分から
 * 優先的に消し込むため、1件の有給申請の承認が複数のpaid_leave_grantにまたがる場合、
 * grantごとに1行作成される。
 */
#[Fillable(['stored_event_id', 'usage_id', 'user_id', 'attendance_day_id', 'paid_leave_grant_id', 'paid_leave_request_id', 'used_on', 'used_days', 'used_minutes', 'usage_type', 'is_confirmed', 'confirmed', 'cancelled'])]
class PaidLeaveUsage extends Model
{
    /**
     * `paid_leave_usages`は旧`PaidLeave`ドメインと新`PaidLeaveAccount`ドメイン
     * (docs/changesets/20260906-paid-leave-domain-redesign/spec.md)が同じ物理テーブルを
     * 共有する(論点7)。このモデルは旧ドメイン専用であり、既存のController/テストの
     * `where('user_id', ...)`のような素朴なクエリが新ドメイン側の行(`usage_id`列が
     * 設定されている行)を誤って拾わないよう、既定で`usage_id`が未設定の行のみを対象にする
     * (デフォルトスコープ)。新ドメイン側の行を扱う場合は`App\Models\PaidLeaveAccountUsage`を
     * 使う(同じテーブルを指す姉妹モデル)。
     */
    protected static function booted(): void
    {
        static::addGlobalScope('legacyPaidLeaveDomain', function ($query) {
            $query->whereNull('usage_id');
        });
    }

    protected function casts(): array
    {
        return [
            'used_on' => 'date',
            'used_days' => 'decimal:1',
            'is_confirmed' => 'boolean',
            'confirmed' => 'boolean',
            'cancelled' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<PaidLeaveGrant, $this>
     */
    public function grant(): BelongsTo
    {
        return $this->belongsTo(PaidLeaveGrant::class, 'paid_leave_grant_id');
    }

    /**
     * @return BelongsTo<PaidLeaveRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(PaidLeaveRequest::class, 'paid_leave_request_id');
    }
}
