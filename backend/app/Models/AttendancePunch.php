<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 打刻ログ (docs/03-architecture.md 3.3)。勤怠の正ではなく参考情報。
 * 同一user_id・work_dateの打刻群がUC-A012の意味で整合している場合のみ
 * attendance_days / attendance_breaks に反映される。
 */
#[Fillable([
    'user_id', 'work_date', 'punch_type', 'punched_at', 'utc_offset_minutes', 'source', 'note',
    'status', 'correction_reason', 'corrected_by_user_id', 'corrected_at', 'superseded_by_punch_id',
    'device_id', 'authentication_key_id', 'actor_user_id', 'integration_id', 'offline',
    'idempotency_key', 'request_id', 'metadata_json',
])]
class AttendancePunch extends Model
{
    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'punched_at' => 'datetime',
            'corrected_at' => 'datetime',
            'offline' => 'boolean',
            'metadata_json' => 'array',
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
     * @return BelongsTo<User, $this>
     */
    public function correctedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * @return BelongsTo<AuthenticationKey, $this>
     */
    public function authenticationKey(): BelongsTo
    {
        return $this->belongsTo(AuthenticationKey::class);
    }

    /**
     * @return BelongsTo<AttendancePunch, $this>
     */
    public function supersededByPunch(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_punch_id');
    }

    public function isActive(): bool
    {
        return $this->status === PunchStatus::ACTIVE;
    }
}
