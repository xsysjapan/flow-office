<?php

namespace App\Domain\AssetNumbering\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * カテゴリ別ルール(`category`を指定)、またはデフォルトルール(`category`はnull)を
 * 作成・更新する。`category`と`is_default`を同時に指定できる構造は持たず、`category`が
 * nullかどうかだけでどちらの行を対象にするか決まる
 * (docs/changesets/20260831-asset-management-refinement/spec.md 論点10)。
 */
class ConfigureAssetNumberRule implements Command
{
    public function __construct(
        public readonly ?string $category,
        public readonly string $prefix,
        public readonly int $digitCount,
        public readonly bool $enabled,
        public readonly string $actorUserId,
    ) {}
}
