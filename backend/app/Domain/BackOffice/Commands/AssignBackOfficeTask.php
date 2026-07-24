<?php

namespace App\Domain\BackOffice\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-B002: 担当者を割り当てる。
 */
class AssignBackOfficeTask implements Command
{
    public function __construct(
        public readonly string $backOfficeTaskId,
        public readonly string $assignedUserId,
        public readonly string $assignedByUserId,
    ) {}
}
