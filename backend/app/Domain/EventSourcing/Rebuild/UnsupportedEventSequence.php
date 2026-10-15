<?php

namespace App\Domain\EventSourcing\Rebuild;

use RuntimeException;

/**
 * 変換規則で扱えないイベントの並び(推測で埋めない)。試し実行で一覧にして失敗させる。
 */
final class UnsupportedEventSequence extends RuntimeException
{
    public function __construct(
        public readonly string $key,
        string $reason,
    ) {
        parent::__construct("[{$key}] {$reason}");
    }
}
