<?php

namespace App\Domain\User\Commands;

use App\Domain\EventSourcing\Contracts\Command;

class BackfillUserRoles implements Command
{
    public function __construct() {}
}
