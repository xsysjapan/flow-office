<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-A012: 打刻ログを記録する。画面のクロックイン/クロックアウトとは別の経路で、
 * 将来ICカード端末やモバイル端末などから打刻を受け付けるための入口。
 * 矛盾があっても記録自体は必ず成功させ、矛盾の有無の判定はHandler側で行う。
 */
class RecordAttendancePunch implements Command
{
    public function __construct(
        public readonly int $userId,
        public readonly string $workDate,
        public readonly string $punchType,
        public readonly string $punchedAt,
        public readonly string $source,
        public readonly ?string $note,
    ) {}
}
