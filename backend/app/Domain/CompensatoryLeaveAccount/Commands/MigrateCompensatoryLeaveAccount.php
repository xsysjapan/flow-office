<?php

namespace App\Domain\CompensatoryLeaveAccount\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 本変更前の代休の付与・消化記録を、利用者単位の口座へ引き継ぐ(運用コマンドが発行する。一度だけ)。
 * grants・usagesは移行対象の現在の状態(CompensatoryLeaveAccountAggregate::migrate参照)。
 */
class MigrateCompensatoryLeaveAccount implements Command
{
    /**
     * @param  array<int, array<string, mixed>>  $grants
     * @param  array<int, array<string, mixed>>  $usages
     */
    public function __construct(
        public readonly string $userId,
        public readonly array $grants,
        public readonly array $usages,
    ) {}
}
