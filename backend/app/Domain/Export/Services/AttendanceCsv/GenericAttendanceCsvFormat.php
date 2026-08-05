<?php

namespace App\Domain\Export\Services\AttendanceCsv;

use App\Models\AttendanceMonth;

/**
 * 既定フォーマット(後方互換)。カンマ区切り・UTF-8・分単位の整数。
 * 既存の`attendance_{year_month}.csv`という命名・列構成をそのまま維持する。
 */
class GenericAttendanceCsvFormat implements AttendanceCsvFormat
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
            $snapshot['prescribed_work_minutes'] ?? 0,
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
        return 'UTF-8';
    }

    public function fileExtension(): string
    {
        return 'csv';
    }
}
