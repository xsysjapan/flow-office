<?php

namespace App\Domain\AssetNumbering\Aggregates;

use App\Domain\AssetNumbering\Events\AssetNumberIssued;
use App\Domain\AssetNumbering\Events\AssetNumberRuleConfigured;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * `asset_number_rules`は正データがEloquentモデル側にあるマスタであり、このAggregateは
 * 状態の再生には使わず、設定変更・採番の監査ログを`stored_events`に記録するためだけに存在する
 * (`App\Domain\SystemSettings\Aggregates\SystemSettingsAuditAggregate`と同じ方針)。
 */
class AssetNumberRuleAuditAggregate extends AggregateRoot
{
    public function recordConfigured(
        int $assetNumberRuleId,
        ?string $category,
        string $prefix,
        int $digitCount,
        bool $enabled,
        bool $isDefault,
        string $actorUserId,
    ): self {
        $this->recordThat(new AssetNumberRuleConfigured(
            assetNumberRuleId: $assetNumberRuleId,
            category: $category,
            prefix: $prefix,
            digitCount: $digitCount,
            enabled: $enabled,
            isDefault: $isDefault,
            actorUserId: $actorUserId,
        ));

        return $this;
    }

    public function recordIssued(
        int $assetNumberRuleId,
        string $category,
        int $issuedNumber,
        string $assetNo,
        string $actorUserId,
    ): self {
        $this->recordThat(new AssetNumberIssued(
            assetNumberRuleId: $assetNumberRuleId,
            category: $category,
            issuedNumber: $issuedNumber,
            assetNo: $assetNo,
            actorUserId: $actorUserId,
        ));

        return $this;
    }
}
