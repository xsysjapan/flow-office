<?php

namespace App\Domain\PaidLeaveSchedule\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 対象社員1名について、1年先までScheduleエントリの存在を保証する(べき等)。
 * Phase C以降のバッチ(`paid-leave:roll-schedules`)がユーザーごとにこれを発行する
 * (spec.md 実装対象参照。本Phaseではバッチのループ自体は実装しない)。
 */
class EnsureFutureScheduleGenerated implements Command
{
    public function __construct(
        public readonly string $userId,
    ) {}
}
