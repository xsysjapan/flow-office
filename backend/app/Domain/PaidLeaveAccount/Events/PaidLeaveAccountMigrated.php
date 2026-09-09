<?php

namespace App\Domain\PaidLeaveAccount\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * 旧システム/旧Aggregateからのcutover移行時、口座ごとに1回だけ発行される。通常の`grant()`が
 * 課す不変条件(直前Grantより後の日付であること等)を経由せず、
 * `PaidLeaveAccountAggregate::migrateGrants()`から発行する。
 *
 * `originalGrantedOn`/`originalGrantedDays`は移行モードA/Bでのみ判明する付随情報
 * (監査・表示専用)であり、モードC(残高と有効期限のみ判明)ではnullのまま記録される。
 * 不変条件上の実際の上限は常に`remainingDaysAtCutover`(切替時点の残日数)であり、
 * `originalGrantedDays`が未知でも架空の上限を補わない
 * (docs/changesets/20260906-paid-leave-domain-redesign/spec.md「既存データ移行」参照)。
 *
 * @param  array<int, array{grantId: string, originalGrantedOn: ?string, originalGrantedDays: ?float, remainingDaysAtCutover: float, expiresOn: string, source: string, cutoverMetadata: ?array}>  $grants
 */
class PaidLeaveAccountMigrated extends ShouldBeStored
{
    public function __construct(
        public readonly string $cutoverDate,
        public readonly array $grants,
    ) {}
}
