<?php

namespace App\Domain\EventSourcing\Contracts;

use App\Models\StoredEvent;

/**
 * Projection Table (再生成可能な読み取り専用データ) を更新する。
 * docs/03-architecture.md 3.2節、.claude/skills/add-projection を参照。
 */
interface Projector
{
    /**
     * このProjectorが購読するイベント種別。
     *
     * @return array<int, string>
     */
    public function eventTypes(): array;

    /**
     * 1件のイベントをProjection Tableへ反映する。同じイベントを複数回適用しても
     * 結果が変わらないよう(冪等に)実装すること。
     */
    public function project(StoredEvent $event): void;

    /**
     * 再生成のために、このProjectorが管理するProjection Tableを空にする。
     */
    public function reset(): void;
}
