<?php

namespace App\Domain\Export\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * export.created (UC-E001/UC-E002: 出力履歴を記録する)
 */
class ExportCreated extends ShouldBeStored
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        public readonly string $exportType,
        public readonly array $params,
        public readonly string $requestedByUserId,
        public readonly int $rowCount,
        /**
         * 冪等性キー(対象データID+出力種別+実行回数)。既存の勤怠CSV等では未使用のため
         * nullを許容する後方互換フィールド。
         */
        public readonly ?string $idempotencyKey = null,
    ) {}

}
