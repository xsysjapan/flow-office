<?php

namespace App\Domain\Export\Services\AttendanceCsv;

use App\Models\AttendanceMonth;

/**
 * genericと同じ列・値(カンマ区切り・分単位)だが、文字コードをShift-JISにする。
 * レガシーなWindows系ソフト向け。
 */
class GenericSjisAttendanceCsvFormat implements AttendanceCsvFormat
{
    public function header(): array
    {
        return [
            'user_id', 'user_name', 'year_month', 'work_minutes', 'prescribed_work_minutes',
            'statutory_within_overtime_minutes', 'statutory_excess_overtime_minutes', 'late_night_work_minutes',
            'legal_holiday_work_minutes', 'prescribed_holiday_work_minutes',
        ];
    }

    public function row(AttendanceMonth $month, string $yearMonth): array
    {
        $snapshot = $month->snapshot_json ?? [];

        return [
            $month->user_id,
            $month->user?->name,
            $yearMonth,
            $snapshot['work_minutes'] ?? 0,
            $this->scheduledPrescribedMinutes($snapshot),
            $snapshot['statutory_within_overtime_minutes'] ?? 0,
            $snapshot['statutory_excess_overtime_minutes'] ?? 0,
            $snapshot['late_night_work_minutes'] ?? 0,
            $snapshot['legal_holiday_work_minutes'] ?? 0,
            $snapshot['prescribed_holiday_work_minutes'] ?? 0,
        ];
    }

    public function delimiter(): string
    {
        return ',';
    }

    public function encoding(): string
    {
        return 'SJIS-win';
    }

    public function fileExtension(): string
    {
        return 'csv';
    }

    /**
     * 所定労働時間(勤務予定ベース)。キーが無い古いsnapshotは従来の prescribed_work_minutes を使う。
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function scheduledPrescribedMinutes(array $snapshot): int
    {
        if (array_key_exists('scheduled_prescribed_work_minutes', $snapshot)) {
            return (int) $snapshot['scheduled_prescribed_work_minutes'];
        }

        return (int) ($snapshot['prescribed_work_minutes'] ?? 0);
    }
}
