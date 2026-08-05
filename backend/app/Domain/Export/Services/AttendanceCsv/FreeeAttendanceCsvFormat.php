<?php

namespace App\Domain\Export\Services\AttendanceCsv;

use App\Models\AttendanceMonth;
use Illuminate\Support\Carbon;

/**
 * freee人事労務の「勤怠サマリー」形式を模した専用フォーマット。
 * 値は全て分単位の整数。カンマ区切り・UTF-8。
 */
class FreeeAttendanceCsvFormat implements AttendanceCsvFormat
{
    public function header(): array
    {
        return [
            '従業員番号', '集計開始日', '集計終了日', '所定労働時間', '法定内残業時間',
            '時間外労働時間', '深夜労働時間', '法定休日労働時間', '総労働時間',
        ];
    }

    public function row(AttendanceMonth $month, string $yearMonth): array
    {
        $snapshot = $month->snapshot_json ?? [];
        $startOfMonth = Carbon::createFromFormat('Y-m-d', $yearMonth.'-01')->startOfMonth();
        $endOfMonth = $startOfMonth->copy()->endOfMonth();

        return [
            $month->user_id,
            $startOfMonth->format('Y/m/d'),
            $endOfMonth->format('Y/m/d'),
            $snapshot['prescribed_work_minutes'] ?? 0,
            $snapshot['statutory_within_overtime_minutes'] ?? 0,
            $snapshot['statutory_excess_overtime_minutes'] ?? 0,
            $snapshot['late_night_work_minutes'] ?? 0,
            $snapshot['legal_holiday_work_minutes'] ?? 0,
            $snapshot['work_minutes'] ?? 0,
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
