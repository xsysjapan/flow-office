<?php

namespace App\Domain\EventSourcing\Support;

use App\Models\StoredEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;

/**
 * 監査ログ(UC-M003)・申請/有給等の履歴表示で、legacy_stored_events(未移行ドメイン)と
 * stored_events(spatie移行済みドメイン)の両方を横断して検索するためのヘルパー。
 * ドメインの移行が進むにつれ両テーブルを見る必要があるための一時的な仕組みであり、
 * 全ドメイン移行後のバックフィル(docs/29-event-sourcing-framework-migration.md
 * 「最終的なデータ移行」参照)が完了すれば、legacy側の検索は不要になり削除する。
 *
 * 新テーブル側は aggregate_type 列を持たないため、event_class の
 * "<aggregate>.<past_tense_verb>" 命名規則(docs/17-events.md)を使い、
 * "<aggregate_type>." 前方一致で代用する。
 * 新テーブル側の event_properties はイベントクラスの公開プロパティ名(camelCase)を
 * そのままシリアライズしたものなので、旧payload(snake_case)のキーとは異なる。
 */
class EventHistoryQuery
{
    /**
     * ペイロード中でユーザーIDを表す代表的なキー(旧stored_events.payloadのsnake_case表記)。
     *
     * @var array<int, string>
     */
    private const ACTOR_KEYS = [
        'user_id', 'applicant_user_id', 'approver_user_id', 'approved_by_user_id',
        'submitted_by_user_id', 'returned_by_user_id', 'cancelled_by_user_id',
        'changed_by_user_id', 'assigned_by_user_id', 'assigned_user_id', 'edited_by_user_id',
        'closed_by_user_id', 'requested_by_user_id',
        'owner_user_id', 'registered_by_user_id', 'issued_by_user_id', 'disabled_by_user_id',
        'revoked_by_user_id', 'granted_by_user_id', 'actor_user_id', 'created_by_user_id',
        'updated_by_user_id', 'applied_by_user_id', 'confirmed_by_user_id', 'reissued_by_user_id',
    ];

    /**
     * @return Collection<int, object>
     */
    public function search(
        ?string $aggregateType = null,
        ?string $aggregateId = null,
        ?string $eventType = null,
        ?int $userId = null,
        ?string $from = null,
        ?string $to = null,
    ): Collection {
        $legacy = $this->legacyQuery($aggregateType, $aggregateId, $eventType, $userId, $from, $to)
            ->get()
            ->map(fn (StoredEvent $event) => (object) [
                'id' => 'legacy-'.$event->id,
                'event_id' => $event->event_id,
                'aggregate_type' => $event->aggregate_type,
                'aggregate_id' => $event->aggregate_id,
                'version' => $event->version,
                'event_type' => $event->event_type,
                'payload' => $event->payload,
                'occurred_at' => $event->occurred_at,
            ]);

        $migrated = $this->migratedQuery($aggregateType, $aggregateId, $eventType, $userId, $from, $to)
            ->get()
            ->map(fn (EloquentStoredEvent $event) => (object) [
                'id' => 'new-'.$event->id,
                'event_id' => (string) $event->id,
                'aggregate_type' => Str::before($event->event_class, '.'),
                'aggregate_id' => $event->aggregate_uuid,
                'version' => $event->aggregate_version,
                'event_type' => $event->event_class,
                'payload' => $event->event_properties,
                'occurred_at' => Carbon::parse($event->created_at),
            ]);

        return $legacy->concat($migrated)->sortByDesc('occurred_at')->values();
    }

    private function legacyQuery(
        ?string $aggregateType,
        ?string $aggregateId,
        ?string $eventType,
        ?int $userId,
        ?string $from,
        ?string $to,
    ): Builder {
        $query = StoredEvent::query();

        if ($aggregateType !== null) {
            $query->where('aggregate_type', $aggregateType);
        }

        if ($aggregateId !== null) {
            $query->where('aggregate_id', $aggregateId);
        }

        if ($eventType !== null) {
            $query->where('event_type', $eventType);
        }

        if ($from !== null) {
            $query->where('occurred_at', '>=', $from);
        }

        if ($to !== null) {
            $query->where('occurred_at', '<=', $to);
        }

        if ($userId !== null) {
            $query->where(function (Builder $sub) use ($userId) {
                foreach (self::ACTOR_KEYS as $key) {
                    $sub->orWhere('payload', 'like', '%"'.$key.'":'.$userId.'%');
                }
                $sub->orWhere(fn (Builder $q) => $q->where('aggregate_type', 'user')->where('aggregate_id', (string) $userId));
            });
        }

        return $query;
    }

    private function migratedQuery(
        ?string $aggregateType,
        ?string $aggregateId,
        ?string $eventType,
        ?int $userId,
        ?string $from,
        ?string $to,
    ): Builder {
        $query = EloquentStoredEvent::query();

        if ($aggregateType !== null) {
            $query->where('event_class', 'like', $aggregateType.'.%');
        }

        if ($aggregateId !== null) {
            $query->where('aggregate_uuid', $aggregateId);
        }

        if ($eventType !== null) {
            $query->where('event_class', $eventType);
        }

        if ($from !== null) {
            $query->where('created_at', '>=', $from);
        }

        if ($to !== null) {
            $query->where('created_at', '<=', $to);
        }

        if ($userId !== null) {
            $query->where(function (Builder $sub) use ($userId) {
                foreach (self::ACTOR_KEYS as $key) {
                    $sub->orWhere('event_properties', 'like', '%"'.Str::camel($key).'":'.$userId.'%');
                }
            });
        }

        return $query;
    }
}
