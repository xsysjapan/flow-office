<?php

namespace App\Domain\EventSourcing\Contracts;

/**
 * stored_events に記録される1件のドメインイベント。
 * 実装クラスはイミュータブルなDTOとして書く(公開プロパティ+readonly推奨)。
 */
interface DomainEvent
{
    /**
     * docs/17-events.md の命名規則 (`<aggregate>.<past_tense_verb>`) に従うイベント種別。
     */
    public function eventType(): string;

    /**
     * stored_events.payload に保存するデータ。
     *
     * @return array<string, mixed>
     */
    public function payload(): array;
}
