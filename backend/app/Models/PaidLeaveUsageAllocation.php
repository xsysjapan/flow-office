<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * `App\Domain\PaidLeaveAccount\Events\PaidLeaveUsageAllocated`/
 * `PaidLeaveUsageAllocationReleased`から作成・更新されるProjection。UsageとGrantの
 * 充当関係のSource of Truth(docs/changesets/20260906-paid-leave-domain-redesign/spec.md
 * 論点7)。`usage_id`/`grant_id`はPaidLeaveAccountAggregateがコマンド側で発行するUUID文字列。
 */
#[Fillable(['usage_id', 'grant_id', 'allocated_days'])]
class PaidLeaveUsageAllocation extends Model
{
    protected function casts(): array
    {
        return [
            'allocated_days' => 'decimal:1',
        ];
    }
}
