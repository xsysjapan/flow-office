<?php

namespace App\Domain\ExternalIntegration\Aggregates;

use App\Domain\ExternalIntegration\Events\ExternalIntegrationConnectionCreated;
use App\Domain\ExternalIntegration\Events\ExternalIntegrationConnectionDeleted;
use App\Domain\ExternalIntegration\Events\ExternalIntegrationConnectionUpdated;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * 外部連携(freee/マネーフォワード)設定の監査ログ用アグリゲート。system_settingsと同様、
 * 実データはExternalIntegrationConnectionモデルへ管理者専用APIから直接更新するが、
 * 監査目的のイベントは同一トランザクションでstored_eventsに記録する
 * (backend/CLAUDE.md、docs/03-architecture.md 3.1の例外パターン)。
 */
class ExternalIntegrationConnectionAuditAggregate extends AggregateRoot
{
    public function recordCreate(array $after, string $actor): self
    {
        $this->recordThat(new ExternalIntegrationConnectionCreated($after, $actor));

        return $this;
    }

    public function recordUpdate(array $before, array $after, string $actor): self
    {
        $this->recordThat(new ExternalIntegrationConnectionUpdated($before, $after, $actor));

        return $this;
    }

    public function recordDelete(array $before, string $actor): self
    {
        $this->recordThat(new ExternalIntegrationConnectionDeleted($before, $actor));

        return $this;
    }
}
