<?php

namespace App\Domain\EventSourcing\Rebuild;

/**
 * 休暇(有給・特別休暇・代休)の1申請の変換結果。
 *
 * @property-read list<RebuiltEvent> $events 変換後の行(申請集約・口座集約)
 * @property-read list<int> $consumedIds 変換で置き換えた元の stored_events.id(写さない)
 * @property-read ?string $userId 申請者
 * @property-read ?string $targetDate 休暇の対象日(Y-m-d)
 * @property-read ?string $leaveType 休暇の種類(full・am_half・pm_half・hourly)
 * @property-read ?array{row: StoredEventRow, operatorUserId: string} $lastRelease 有効な消化を解除した最後の操作(差戻し・取消・却下)。無ければnull
 */
final readonly class PaidLeaveRequestRebuild
{
    /**
     * @param  list<RebuiltEvent>  $events
     * @param  list<int>  $consumedIds
     * @param  array{row: StoredEventRow, operatorUserId: string}|null  $lastRelease
     */
    public function __construct(
        public array $events,
        public array $consumedIds,
        public string $finalState,
        public int $resubmitCount,
        public ?string $userId = null,
        public ?string $targetDate = null,
        public ?array $lastRelease = null,
        public ?string $leaveType = null,
    ) {}

    /** 休暇が有効(申請中・承認済み)か。 */
    public function isActive(): bool
    {
        return in_array($this->finalState, ['requested', 'shared', 'approved'], true);
    }
}
