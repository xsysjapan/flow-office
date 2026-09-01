<?php

namespace App\Domain\AssetNumbering\Handlers;

use App\Domain\AssetNumbering\Aggregates\AssetNumberRuleAuditAggregate;
use App\Domain\AssetNumbering\Commands\IssueAssetNumber;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Models\AssetNumberRule;
use Ramsey\Uuid\Uuid;

/**
 * 管理番号を自動採番する。判定順序は spec 論点10:
 * ①`category`完全一致かつ`enabled=true` → ②(①が無い場合のみ)デフォルトルール
 * (`is_default=true`かつ`enabled=true`) → ③いずれも無ければ自動採番不可(null)。
 * ①の行が存在するが`enabled=false`の場合はデフォルトへフォールバックしない
 * (=そのカテゴリは明示的に自動採番しない意思表示のため)。
 *
 * @implements CommandHandler<IssueAssetNumber>
 */
class IssueAssetNumberHandler implements CommandHandler
{
    public function handle(Command $command): ?string
    {
        assert($command instanceof IssueAssetNumber);

        $rule = AssetNumberRule::query()->where('category', $command->category)->lockForUpdate()->first();

        if ($rule !== null) {
            return $rule->enabled ? $this->issue($rule, $command->category, $command->actorUserId) : null;
        }

        $defaultRule = AssetNumberRule::query()->where('is_default', true)->lockForUpdate()->first();

        if ($defaultRule !== null && $defaultRule->enabled) {
            return $this->issue($defaultRule, $command->category, $command->actorUserId);
        }

        return null;
    }

    private function issue(AssetNumberRule $rule, string $category, string $actorUserId): string
    {
        $issuedNumber = $rule->next_number;
        $rule->next_number = $issuedNumber + 1;
        $rule->save();

        $assetNo = $rule->prefix.'-'.str_pad((string) $issuedNumber, $rule->digit_count, '0', STR_PAD_LEFT);

        $aggregateId = Uuid::uuid5(Uuid::NAMESPACE_URL, 'asset-number-rule:'.($rule->category ?? '__default__'))->toString();
        AssetNumberRuleAuditAggregate::retrieve($aggregateId)
            ->recordIssued(
                assetNumberRuleId: $rule->id,
                category: $category,
                issuedNumber: $issuedNumber,
                assetNo: $assetNo,
                actorUserId: $actorUserId,
            )
            ->persist();

        return $assetNo;
    }
}
