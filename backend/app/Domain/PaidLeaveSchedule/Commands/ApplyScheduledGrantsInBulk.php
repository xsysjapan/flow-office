<?php

namespace App\Domain\PaidLeaveSchedule\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 付与予定の一括付与(複数の利用者の行をまとめて付与する)。全部か無しか: 1行でも付与できなければ何も記録しない。
 * 利用者ごとに`ApplyScheduledGrants`を行う。返り値は`['granted' => [['scheduleEntryId', 'grantId'], ...]]`。
 */
class ApplyScheduledGrantsInBulk implements Command
{
    /**
     * @param  list<string>  $scheduleEntryIds
     */
    public function __construct(
        public readonly array $scheduleEntryIds,
        public readonly string $operatorUserId,
    ) {}
}
