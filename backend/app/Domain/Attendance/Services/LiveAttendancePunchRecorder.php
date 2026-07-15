<?php

namespace App\Domain\Attendance\Services;

use App\Domain\Attendance\Events\AttendancePunchRecorded;
use App\Domain\EventSourcing\EventStore;
use App\Models\AttendanceDay;
use App\Models\AttendancePunch;
use App\Support\LocalDateTime;
use Illuminate\Support\Carbon;

/** Records Web clock actions as reference punch logs without re-syncing the confirmed attendance day. */
class LiveAttendancePunchRecorder
{
    public function __construct(private readonly EventStore $eventStore) {}

    public function record(AttendanceDay $day, string $punchType, Carbon $punchedAt): AttendancePunch
    {
        $punch = AttendancePunch::query()->create([
            'user_id' => $day->user_id,
            'work_date' => $day->work_date->toDateString(),
            'punch_type' => $punchType,
            'punched_at' => $punchedAt,
            'utc_offset_minutes' => $day->utc_offset_minutes,
            'source' => 'web',
        ]);

        $this->eventStore->append(
            aggregateType: 'attendance_punch',
            aggregateId: (string) $punch->id,
            event: new AttendancePunchRecorded(
                attendancePunchId: $punch->id,
                userId: $punch->user_id,
                workDate: $punch->work_date->toDateString(),
                punchType: $punch->punch_type,
                punchedAt: LocalDateTime::formatWithOffsetMinutes($punch->punched_at, $punch->utc_offset_minutes),
                source: $punch->source,
            ),
        );

        return $punch;
    }
}