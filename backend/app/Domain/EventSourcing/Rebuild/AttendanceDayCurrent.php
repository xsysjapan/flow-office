<?php

namespace App\Domain\EventSourcing\Rebuild;

/**
 * ReadModel(attendance_days)の現在値(論点18 2.4: 旧コードが直接書いた勤怠の事実はReadModelにしか無いため、この範囲に限り入力に使う)。
 *
 * 日時は stored(壁時計の 'Y-m-d H:i:s')のまま持つ。AttendanceRebuilder が utcOffsetMinutes を付けてISO8601にする。
 */
final readonly class AttendanceDayCurrent
{
    /**
     * @param  list<array{start: string, end: ?string}>  $breaks  stored の日時
     * @param  list<array{start: string, end: string, note: ?string}>  $leaveSegments  stored の日時
     */
    public function __construct(
        public string $id,
        public string $userId,
        public string $workDate,
        public ?string $calendarEntryId,
        public string $status,
        public string $source,
        public int $utcOffsetMinutes,
        public ?string $actualStartAt,
        public ?string $actualEndAt,
        public ?string $workType,
        public ?string $workLocationType,
        public ?string $dayClassification,
        public ?string $note,
        public array $breaks = [],
        public array $leaveSegments = [],
        public bool $hasPunch = false,
    ) {}

    public function hasActual(): bool
    {
        return $this->actualStartAt !== null || $this->actualEndAt !== null;
    }
}
