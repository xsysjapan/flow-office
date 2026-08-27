<?php

namespace App\Domain\Export\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * internal_archive.created (UC-X012: 経費の証跡アーカイブExcelを内部保管したことを記録する)。
 * $idempotencyKey は「対象データID+出力種別+実行回数」で構成する(同一対象・同一実行回数の
 * 再実行を検知するための監査用キーであり、DBの一意制約は設けない)。
 */
class InternalArchiveCreated extends ShouldBeStored
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        public readonly string $exportType,
        public readonly string $subjectId,
        public readonly string $idempotencyKey,
        public readonly array $params,
        public readonly string $requestedByUserId,
        public readonly ?string $location,
        public readonly int $rowCount,
    ) {}
}
