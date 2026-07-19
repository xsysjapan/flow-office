<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 個人宛て通知の一覧・既読管理 (docs/13-usecases-notification.md UC-N001)。
 * `App\Domain\Notification\Projectors\NotificationProjector` が`stored_events`から作成・更新する。
 * 主キーはUUID(`SendNotificationJob::enqueue()`が発番)。
 */
#[Fillable(['id', 'recipient_user_id', 'title', 'summary', 'detail_url', 'queued_at', 'sent_at', 'confirmed_at'])]
class Notification extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
