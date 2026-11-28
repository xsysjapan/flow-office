<?php

namespace App\Domain\AccessControl\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * ロール詳細のまとめて保存(docs/changesets/20261128-single-command-entrypoints/spec.md 論点2)。
 *
 * $changes は送られた項目だけを持つ(name・description・status・permission_ids・feature_ids。
 * キーがあって値が null のものは「null にする」)。
 */
class UpdateRoleDefinition implements Command
{
    public function __construct(public readonly int $roleId, public readonly array $changes, public readonly ?string $actorUserId) {}
}
