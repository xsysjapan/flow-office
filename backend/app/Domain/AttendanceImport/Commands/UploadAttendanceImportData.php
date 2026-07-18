<?php

namespace App\Domain\AttendanceImport\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * Claudeが作業報告書を解析して得た構造化データを受け取る(docs/26「Claudeによる構造化」)。
 * ファイル解析自体はここでは行わない。
 */
class UploadAttendanceImportData implements Command
{
    /**
     * @param  array<int, array<string, mixed>>  $days
     */
    public function __construct(
        public readonly int $sessionId,
        public readonly array $days,
    ) {}
}
