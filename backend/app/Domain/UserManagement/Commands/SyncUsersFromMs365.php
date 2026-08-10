<?php

namespace App\Domain\UserManagement\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-002: MS365ユーザーを同期する。
 */
class SyncUsersFromMs365 implements Command {}
