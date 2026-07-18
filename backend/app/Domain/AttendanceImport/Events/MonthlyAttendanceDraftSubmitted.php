<?php

namespace App\Domain\AttendanceImport\Events;

use App\Domain\EventSourcing\Contracts\DomainEvent;

class MonthlyAttendanceDraftSubmitted implements DomainEvent
{
    public function __construct(
        public readonly int $draftId,
        public readonly int $attendanceMonthId,
        public readonly int $submittedByUserId,
    ) {}

    public function eventType(): string
    {
        return 'monthly_attendance_draft.submitted';
    }

    public function payload(): array
    {
        return [
            'draft_id' => $this->draftId,
            'attendance_month_id' => $this->attendanceMonthId,
            'submitted_by_user_id' => $this->submittedByUserId,
        ];
    }
}
