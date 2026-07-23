<?php

namespace App\Domain\Leave\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;

/**
 * 有給休暇・特別休暇はビジネスロジック(Command/Handler)を完全に分けて実装するが、
 * 「対象社員が持つgrant/requestのidで絞り込み、stored_eventsを時系列で返す」という
 * 読み取り専用のQueryの形は共通のため、ここに切り出して両ドメインのControllerから使う
 * (docs/09-usecases-paid-leave.md UC-P007と同じ考え方)。
 *
 * `request_approved`/`request_returned`/`request_cancelled`のpayloadには実行者
 * (承認者等)のIDのみが含まれ申請者本人のuser_idを含まないため、payloadの内容ではなく
 * 対象社員が実際に持つgrant/requestのid(=aggregate_uuid)で絞り込む必要がある点に注意する。
 *
 * PaidLeave/SpecialLeaveは共にspatie/laravel-event-sourcingへ移行済みのため、新テーブル
 * (stored_events)のみを検索する。新テーブル側は集約ごとのevent_class接頭辞を持たない
 * (例: paid_leave_grant/paid_leave_requestどちらも"paid_leave."で始まる)ため、
 * aggregate_uuidのみで絞り込む。またevent_propertiesはイベントクラスの公開プロパティ名
 * (camelCase)のままなので、レスポンス形状を変えないよう旧payload(snake_case)のキーに変換する
 * (docs/29-event-sourcing-framework-migration.md参照)。
 */
final class LeaveHistoryQuery
{
    /**
     * @param  class-string<Model>  $grantModelClass
     * @param  class-string<Model>  $requestModelClass
     * @return Collection<int, object>
     */
    public static function eventsForUser(
        int $userId,
        string $grantModelClass,
        string $requestModelClass,
    ): Collection {
        $grantIds = $grantModelClass::query()->where('user_id', $userId)->pluck('id');
        $requestIds = $requestModelClass::query()->where('user_id', $userId)->pluck('id');
        $aggregateIds = $grantIds->merge($requestIds);

        return EloquentStoredEvent::query()
            ->whereIn('aggregate_uuid', $aggregateIds)
            ->orderByDesc('id')
            ->get()
            ->map(fn (EloquentStoredEvent $event) => (object) [
                'id' => $event->id,
                'event_id' => (string) $event->id,
                'aggregate_type' => Str::before($event->event_class, '.'),
                'aggregate_id' => $event->aggregate_uuid,
                'version' => $event->aggregate_version,
                'event_type' => $event->event_class,
                'payload' => collect($event->event_properties)
                    ->mapWithKeys(fn ($value, string $key) => [Str::snake($key) => $value])
                    ->all(),
                'occurred_at' => Carbon::parse($event->created_at),
            ]);
    }
}
