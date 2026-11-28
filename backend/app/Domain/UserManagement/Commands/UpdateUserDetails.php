<?php

namespace App\Domain\UserManagement\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 利用者詳細のまとめて保存(docs/changesets/20261128-single-command-entrypoints/spec.md 論点1)。
 *
 * $profile は変更したプロフィール項目(キーは name・email 等)。$fields は送られた項目だけを持つ
 * (hire_date・termination_date・usage_start_date・paid_leave_auto_grant_enabled・special_leave_auto_grant_enabled。
 * キーがあって値が null のものは「null にする」)。
 */
class UpdateUserDetails implements Command
{
    public function __construct(
        public readonly string $userId,
        public readonly array $profile,
        public readonly array $fields,
        public readonly string $changedByUserId,
    ) {}
}
