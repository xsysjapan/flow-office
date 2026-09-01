<?php

namespace App\Domain\AssetNumbering\Handlers;

use App\Domain\AssetNumbering\Aggregates\AssetNumberRuleAuditAggregate;
use App\Domain\AssetNumbering\Commands\ConfigureAssetNumberRule;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\AssetNumberRule;
use Ramsey\Uuid\Uuid;

/**
 * カテゴリ別ルール、またはデフォルトルール(`category=null`)をupsertする。
 * `is_default=true`の行は最大1件までという制約はDBではなくここで保証する
 * (spec 論点10・実装対象3)。
 *
 * @implements CommandHandler<ConfigureAssetNumberRule>
 */
class ConfigureAssetNumberRuleHandler implements CommandHandler
{
    public function handle(Command $command): AssetNumberRule
    {
        assert($command instanceof ConfigureAssetNumberRule);

        if ($command->category === null) {
            // デフォルトルールは`is_default=true`の行を実質シングルトンとして扱う。
            // 既に存在すればその行を更新し、無ければ新規作成する。
            $rule = AssetNumberRule::query()->where('is_default', true)->lockForUpdate()->first();

            if ($rule === null) {
                $rule = new AssetNumberRule(['is_default' => true, 'category' => null]);
            }
        } else {
            $rule = AssetNumberRule::query()->where('category', $command->category)->lockForUpdate()->first();

            if ($rule === null) {
                $rule = new AssetNumberRule(['is_default' => false, 'category' => $command->category]);
            }
        }

        $rule->prefix = $command->prefix;
        $rule->digit_count = $command->digitCount;
        $rule->enabled = $command->enabled;
        $rule->save();

        $aggregateId = Uuid::uuid5(Uuid::NAMESPACE_URL, 'asset-number-rule:'.($command->category ?? '__default__'))->toString();
        AssetNumberRuleAuditAggregate::retrieve($aggregateId)
            ->recordConfigured(
                assetNumberRuleId: $rule->id,
                category: $rule->category,
                prefix: $rule->prefix,
                digitCount: $rule->digit_count,
                enabled: $rule->enabled,
                isDefault: $rule->is_default,
                actorUserId: $command->actorUserId,
            )
            ->persist();

        return $rule->refresh();
    }
}
