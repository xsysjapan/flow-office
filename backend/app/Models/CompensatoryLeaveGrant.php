<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 代休の付与(App\Domain\CompensatoryLeave参照)。申請ではなく休日出勤の勤怠実績
 * (attendance_days)から自動導出される派生データであり、attendance_day_idをユニークキーに
 * upsertされる。status='draft'の間は月次未提出であることを表し、月次提出時に'confirmed'へ
 * 確定して初めて消化申請の対象になる。主キーはUUID(HasUuids)。この行自体は
 * CompensatoryLeaveGrantProjectorがstored_eventsから作成・更新する。
 */
#[Fillable([
    'id', 'user_id', 'source', 'attendance_day_id', 'work_date', 'granted_days', 'granted_minutes',
    'used_days', 'used_minutes', 'remaining_days', 'remaining_minutes',
    'status', 'confirmed_at', 'expires_on', 'grant_reason',
])]
class CompensatoryLeaveGrant extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'granted_days' => 'decimal:1',
            'used_days' => 'decimal:1',
            'remaining_days' => 'decimal:1',
            'confirmed_at' => 'datetime',
            'expires_on' => 'date',
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
     * @return BelongsTo<AttendanceDay, $this>
     */
    public function attendanceDay(): BelongsTo
    {
        return $this->belongsTo(AttendanceDay::class);
    }

    /**
     * @return HasMany<CompensatoryLeaveUsage, $this>
     */
    public function usages(): HasMany
    {
        return $this->hasMany(CompensatoryLeaveUsage::class, 'compensatory_leave_grant_id');
    }

    /**
     * @return HasMany<CompensatoryLeaveGrantCancellation, $this>
     */
    public function cancellations(): HasMany
    {
        return $this->hasMany(CompensatoryLeaveGrantCancellation::class, 'grant_id');
    }

    /**
     * 指定日時点で消化可能な確定済み付与(失効日が無い、または対象日以降に失効する)に絞り込む。
     *
     * @param  Builder<CompensatoryLeaveGrant>  $query
     * @return Builder<CompensatoryLeaveGrant>
     */
    public function scopeAvailableOn(Builder $query, string $date): Builder
    {
        return $query
            ->where('status', CompensatoryLeaveGrantStatus::CONFIRMED)
            ->where(fn ($q) => $q->whereNull('expires_on')->orWhereDate('expires_on', '>=', $date));
    }
}
