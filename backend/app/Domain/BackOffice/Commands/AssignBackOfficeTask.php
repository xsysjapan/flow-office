<?php

namespace App\Domain\BackOffice\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-B002: 担当者を割り当てる。
 */
class AssignBackOfficeTask implements Command
{
    public function __construct(
        public readonly int $backOfficeTaskId,
        public readonly int $assignedUserId,
        public readonly int $assignedByUserId,
    ) {}
}
