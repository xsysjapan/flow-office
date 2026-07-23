<?php

namespace App\Domain\Attendance\Aggregates;

use App\Domain\Attendance\Events\WorkStyleCreated;
use App\Domain\Attendance\Events\WorkStyleDefaultChanged;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * work_style集約。主キー(work_styles.id)はコマンド側が決めたUUIDで、行の新規作成自体は
 * WorkStyleProjectorに委ねられる。「会社のデフォルト働き方は常に1件」という業務ルールの
 * 判定(既存デフォルトの有無)はHandlerがProjection(Eloquent)の現在値を読んで行う
 * (Deviceドメインと同じ理由。docs/29-event-sourcing-framework-migration.md参照)。
 */
class WorkStyleAggregate extends AggregateRoot
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, string $createdByUserId): self
    {
        $this->recordThat(new WorkStyleCreated(
            attributes: $attributes,
            createdByUserId: $createdByUserId,
        ));

        return $this;
    }

    public function changeDefault(?string $previousDefaultWorkStyleId, string $changedByUserId): self
    {
        $this->recordThat(new WorkStyleDefaultChanged(
            previousDefaultWorkStyleId: $previousDefaultWorkStyleId,
            changedByUserId: $changedByUserId,
        ));

        return $this;
    }
}
