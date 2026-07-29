<?php

namespace App\Domain\ExpenseClaim\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * 「経費精算機能 設計・実装指示書」5.2: 申請タイトル。下書き作成時には入力させず、
 * 任意のタイミングで後から設定・変更できるようにする(UC-X004の「無意味な入力をさせない」
 * 方針を維持するため)。
 */
class UpdateExpenseClaimTitle implements Command
{
    public function __construct(
        public readonly string $claimId,
        public readonly string $updatedByUserId,
        public readonly ?string $title,
    ) {}
}
