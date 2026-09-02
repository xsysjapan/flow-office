<?php

namespace App\Domain\AssetNumbering\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * asset_number_rule.configured
 *
 * 採番ルール(カテゴリ別 or デフォルト)が作成・更新されたことを表す監査イベント。
 * `asset_number_rules`テーブル自体が正データであり、このイベントは再生用ではなく
 * 監査ログ用(ルートCLAUDE.mdの設計原則1の例外規定)。
 */
class AssetNumberRuleConfigured extends ShouldBeStored
{
    public function __construct(
        public readonly int $assetNumberRuleId,
        public readonly ?string $category,
        public readonly string $prefix,
        public readonly int $digitCount,
        public readonly bool $enabled,
        public readonly bool $isDefault,
        public readonly string $actorUserId,
    ) {}
}
