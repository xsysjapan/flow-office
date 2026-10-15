<?php

namespace App\Domain\EventSourcing\Correction;

/**
 * 直接修正の実行結果。alreadyApplied は補正ログに既にあり今回は触らなかった行数(冪等)。
 */
final class StoredEventCorrectionResult
{
    public function __construct(
        public readonly int $applied,
        public readonly int $alreadyApplied,
        public readonly ?string $backupTable,
    ) {}
}
