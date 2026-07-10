<?php

namespace App\Domain\EventSourcing;

use App\Domain\EventSourcing\Contracts\DomainEvent;
use App\Domain\EventSourcing\Exceptions\ConcurrencyException;
use App\Models\StoredEvent;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * stored_events への追記を担う。docs/03-architecture.md 3.2節「EventStoreを正とする」の実装。
 */
class EventStore
{
    public function __construct(private readonly Dispatcher $events) {}

    /**
     * イベントを追記し、Projector向けに StoredEventRecorded を発火する。
     *
     * @param  array<string, mixed>  $metadata
     */
    public function append(string $aggregateType, string $aggregateId, DomainEvent $event, array $metadata = []): StoredEvent
    {
        return DB::transaction(function () use ($aggregateType, $aggregateId, $event, $metadata) {
            // 同一集約への並行書き込みを避けるため、直近のバージョンをロックして取得する。
            $currentVersion = StoredEvent::query()
                ->where('aggregate_type', $aggregateType)
                ->where('aggregate_id', $aggregateId)
                ->lockForUpdate()
                ->max('version') ?? 0;

            $storedEvent = new StoredEvent([
                'event_id' => (string) Str::uuid(),
                'aggregate_type' => $aggregateType,
                'aggregate_id' => $aggregateId,
                'version' => $currentVersion + 1,
                'event_type' => $event->eventType(),
                'payload' => $event->payload(),
                'metadata' => $metadata,
                'occurred_at' => Carbon::now(),
            ]);

            try {
                $storedEvent->save();
            } catch (QueryException $e) {
                throw new ConcurrencyException(
                    "集約 {$aggregateType}#{$aggregateId} への書き込みが競合しました。",
                    previous: $e
                );
            }

            $this->events->dispatch(new StoredEventRecorded($storedEvent));

            return $storedEvent;
        });
    }
}
