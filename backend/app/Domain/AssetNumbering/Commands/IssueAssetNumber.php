<?php

namespace App\Domain\AssetNumbering\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 備品の管理番号を自動採番する。判定順序は spec 論点10:
 * ①`category`完全一致かつ`enabled=true`の行 → ②(①が無い場合のみ)デフォルトルール
 * (`is_default=true`かつ`enabled=true`) → ③いずれも無ければ自動採番不可(null)。
 */
class IssueAssetNumber implements Command
{
    public function __construct(
        public readonly string $category,
        public readonly string $actorUserId,
    ) {}
}
