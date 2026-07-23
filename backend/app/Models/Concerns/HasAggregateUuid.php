<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * 主キーは連番intのまま維持しつつ、別列(aggregate_uuid)でESの集約ストリーム識別子を持つ
 * モデル向け(docs/29-event-sourcing-framework-migration.md「集約IDのUUID化方針」(a)参照)。
 * 本来はCommandHandlerが集約側でUUIDを発番しProjector経由で作成するが、ファクトリ・
 * ユニットテスト等でモデルを直接createする場合に備え、未指定なら自動生成する。
 */
trait HasAggregateUuid
{
    protected static function bootHasAggregateUuid(): void
    {
        static::creating(function ($model) {
            if (empty($model->aggregate_uuid)) {
                $model->aggregate_uuid = (string) Str::uuid();
            }
        });
    }
}
