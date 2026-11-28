<?php

namespace App\Domain\SpecialLeave\Services;

use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\SpecialLeave\Aggregates\SpecialLeaveRequestAggregate;
use App\Models\SpecialLeaveRequest;
use App\Models\SpecialLeaveType;

/**
 * 特別休暇申請の承認の処理。承認のCommand(ApproveSpecialLeaveRequestHandler)と、承認不要の設定での直接の申請
 * (RequestSpecialLeaveHandlerが申請の記録の後に続けて承認を記録する)で共通に使う。
 *
 * - approvedByUserIdがnullの場合は「承認ワークフロー不要」設定による即時承認(承認者チェックを行わない)。
 * - viaReactor=true(ワークフローの承認・まとめ申請の兄弟承認からのReactor発行)では承認者チェックを行わず、
 *   既に承認済みなら何もしない(冪等)。
 */
class SpecialLeaveRequestApprover
{
    public function approve(string $specialLeaveRequestId, ?string $approvedByUserId, bool $viaReactor): SpecialLeaveRequest
    {
        $aggregate = SpecialLeaveRequestAggregate::retrieve($specialLeaveRequestId);

        if ($viaReactor && $aggregate->isApproved()) {
            return SpecialLeaveRequest::query()->findOrFail($specialLeaveRequestId);
        }

        if (! $viaReactor
            && $approvedByUserId !== null
            && $aggregate->approverUserId() !== $approvedByUserId
        ) {
            throw new DomainRuleException('指定された承認者のみ承認できます。');
        }

        $aggregate->approve($approvedByUserId, $this->requiresGrant($aggregate->specialLeaveTypeId()))
            ->persist();

        return SpecialLeaveRequest::query()->findOrFail($specialLeaveRequestId);
    }

    /** 種別が残数(付与)を要するか。忌引・代休等は要しない(種別マスタの値をそのまま使う)。 */
    private function requiresGrant(?int $specialLeaveTypeId): bool
    {
        $specialLeaveType = $specialLeaveTypeId === null ? null : SpecialLeaveType::query()->find($specialLeaveTypeId);

        if ($specialLeaveType === null) {
            throw new DomainRuleException('特別休暇種別が見つかりません。');
        }

        return (bool) $specialLeaveType->requires_grant;
    }
}
