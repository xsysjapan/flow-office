<?php

namespace App\Domain\Asset\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 備品の一括操作(QR一括貸出・一括返却・一括貸与・一括移設)。全部か無しか(1件でも失敗したら何も記録しない)。
 * 各件は`LendAsset`・`ReturnAsset`・`RelocateAsset`の1件の処理と同じ検査・記録で行う。
 * 返り値は`BulkItemRunner::run`の結果(processed・skipped)。
 */
class ApplyAssetBulkOperation implements Command
{
    public const OPERATIONS = ['self_loan', 'self_return', 'backoffice_lend', 'return', 'relocate'];

    /**
     * @param  list<string>  $assetIds
     */
    public function __construct(
        public readonly string $operation,
        public readonly array $assetIds,
        public readonly string $actingUserId,
        public readonly ?string $borrowerUserId = null,
        public readonly ?string $expectedReturnAt = null,
        public readonly ?string $locationText = null,
        public readonly ?string $returnNote = null,
    ) {}
}
