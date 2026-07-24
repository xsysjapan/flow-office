<?php

namespace App\Domain\Attendance\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

/**
 * attendance_punch.corrected
 *
 * UC-A013: 打刻ログを訂正する。打刻ログは追記のみのため、元の打刻(このイベントの集約ルート)は
 * 書き換えず「訂正済み」として残し、訂正後の値を新しい打刻行(correctedPunchId、別のUUID。
 * この打刻自身の集約ストリームは持たない)として追記する。AttendancePunchProjectorが
 * 元の行の状態更新と、新しい訂正後の行の作成の両方を1つのイベントから行う。
 */
class AttendancePunchCorrected extends ShouldBeStored
{
    public function __construct(
        public readonly string $correctedPunchId,
        public readonly string $userId,
        public readonly string $workDate,
        public readonly string $punchType,
        public readonly string $punchedAt,
        public readonly string $source,
        public readonly ?string $note,
        public readonly string $reason,
        public readonly string $correctedByUserId,
    ) {}
}
