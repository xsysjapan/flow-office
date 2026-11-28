<?php

namespace App\Domain\AccessControl\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * ロールの複製(作成と権限の設定を1つのCommandにする。docs/changesets/20261128-single-command-entrypoints/spec.md 論点7)。
 * $description が null のときは複製元の説明を引き継ぐ。
 */
class CloneRole implements Command
{
    public function __construct(
        public readonly int $sourceRoleId,
        public readonly string $code,
        public readonly string $name,
        public readonly ?string $description,
        public readonly ?string $actorUserId,
    ) {}
}
