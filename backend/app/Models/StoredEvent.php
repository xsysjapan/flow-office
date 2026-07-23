<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * legacy_stored_events (旧EventStore実装 App\Domain\EventSourcing\EventStore)の1行。
 * 追記のみを行い、更新・削除はしない。spatie/laravel-event-sourcing に移行済みのドメインは
 * こちらではなく Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent (stored_events
 * テーブル)を使う。移行状況は docs/28-event-sourcing-framework-migration.md を参照。
 */
#[Fillable(['event_id', 'aggregate_type', 'aggregate_id', 'version', 'event_type', 'payload', 'metadata', 'occurred_at'])]
class StoredEvent extends Model
{
    protected $table = 'legacy_stored_events';

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
