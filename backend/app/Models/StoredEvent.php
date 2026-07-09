<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * stored_events (EventStore) の1行。全ドメインイベントの正。
 * 追記のみを行い、更新・削除はしない。
 */
#[Fillable(['event_id', 'aggregate_type', 'aggregate_id', 'version', 'event_type', 'payload', 'metadata', 'occurred_at'])]
class StoredEvent extends Model
{
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
