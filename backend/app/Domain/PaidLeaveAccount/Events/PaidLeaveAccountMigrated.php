<?php

namespace App\Domain\PaidLeaveAccount\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * 旧システム/旧Aggregateからのcutover移行時に1回だけ発行される。通常の`grant()`が課す
 * 不変条件(直前Grantより後の日付であること等)を経由せず、`migrateGrants()`から発行する
 * (Phase 9のMigration Command実装まではイベント名の予約のみ)。
 *
 * @param  array<int, array{originalGrantedOn?: ?string, originalGrantedDays?: ?float, remainingDaysAtCutover: float, expiresOn: string, source: string, cutoverMetadata?: ?array}>  $grants
 */
class PaidLeaveAccountMigrated extends ShouldBeStored
{
    public function __construct(
        public readonly string $cutoverDate,
        public readonly array $grants,
    ) {}
}
