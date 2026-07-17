<?php

namespace App\Domain\Leave\Support;

use App\Models\StoredEvent;
use Illuminate\Database\Eloquent\Collection;

/**
 * 有給休暇・特別休暇はビジネスロジック(Command/Handler)を完全に分けて実装するが、
 * 「対象社員が持つgrant/requestのidで絞り込み、stored_eventsを時系列で返す」という
 * 読み取り専用のQueryの形は共通のため、ここに切り出して両ドメインのControllerから使う
 * (docs/09-usecases-paid-leave.md UC-P007と同じ考え方)。
 *
 * `request_approved`/`request_returned`/`request_cancelled`のpayloadには実行者
 * (承認者等)のIDのみが含まれ申請者本人のuser_idを含まないため、payloadの内容ではなく
 * 対象社員が実際に持つgrant/requestのid(=aggregate_id)で絞り込む必要がある点に注意する。
 */
final class LeaveHistoryQuery
{
    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $grantModelClass
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $requestModelClass
     * @return Collection<int, StoredEvent>
     */
    public static function eventsForUser(
        int $userId,
        string $grantModelClass,
        string $grantAggregateType,
        string $requestModelClass,
        string $requestAggregateType,
    ): Collection {
        $grantIds = $grantModelClass::query()->where('user_id', $userId)->pluck('id')->map(fn ($id) => (string) $id);
        $requestIds = $requestModelClass::query()->where('user_id', $userId)->pluck('id')->map(fn ($id) => (string) $id);

        return StoredEvent::query()
            ->where(function ($query) use ($grantIds, $grantAggregateType, $requestIds, $requestAggregateType) {
                $query->where(fn ($q) => $q->where('aggregate_type', $grantAggregateType)->whereIn('aggregate_id', $grantIds))
                    ->orWhere(fn ($q) => $q->where('aggregate_type', $requestAggregateType)->whereIn('aggregate_id', $requestIds));
            })
            // occurred_atは秒単位までしか保持しないため、同一リクエスト内で複数イベントが
            // 記録された場合に順序が曖昧にならないよう、idを副次的な並び順として使う
            // (idは常に記録順に単調増加するため)。
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get();
    }
}
