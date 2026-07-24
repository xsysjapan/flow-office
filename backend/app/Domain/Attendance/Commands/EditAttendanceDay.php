<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\EventSourcing\Contracts\Command;

/**
 * UC-A005: 日次勤怠を編集する。締め前(ロック前)のみ可能。
 */
class EditAttendanceDay implements Command
{
    /**
     * @param  array<int, array{start: string, end: string}>  $breaks
     * @param  array<int, array{category: string, start: string, end: string, note: string|null}>  $leaveSegments
     */
    public function __construct(
        public readonly string $attendanceDayId,
        public readonly ?string $actualStartAt,
        public readonly ?string $actualEndAt,
        public readonly array $breaks,
        public readonly ?string $workType,
        public readonly ?string $note,
        public readonly array $leaveSegments,
        public readonly string $reason,
        public readonly string $editedByUserId,
        public readonly ?string $workLocationType = null,
        /**
         * work_location_typeがリクエストに含まれていたか。falseの場合、Handlerは
         * 既存の値を維持する(未送信のたびにnullへ上書きされてレコーダー/インポートで
         * 設定済みの値が消えてしまうのを防ぐ)。
         */
        public readonly bool $workLocationTypeProvided = false,
    ) {}
}
