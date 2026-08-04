<?php

namespace App\Domain\Export\Services\AttendanceCsv;

use App\Models\AttendanceMonth;

/**
 * 汎用TSVフォーマット。弥生給与Next・給与奉行クラウドのような「取込時にユーザーが
 * 列・区切り文字・時刻表記を設定できる」汎用取込機能向け。タブ区切り・日本語見出し・
 * 時間はH:MMのコロン表記。UTF-8。
 */
class GenericTsvAttendanceCsvFormat implements AttendanceCsvFormat
{
    public function header(): array
    {
        return [
            '社員番号', '氏名', '対象年月', '実労働時間', '所定労働時間',
            '法定内残業', '法定外残業', '深夜労働時間', '法定休日労働', '所定休日労働',
        ];
    }

    public function row(AttendanceMonth $month, string $yearMonth): array
    {
        $snapshot = $month->snapshot_json ?? [];

        return [
            $month->user_id,
            $month->user?->name,
            $yearMonth,
            $this->toHourMinute($snapshot['work_minutes'] ?? 0),
            $this->toHourMinute($snapshot['prescribed_work_minutes'] ?? 0),
            $this->toHourMinute($snapshot['statutory_within_overtime_minutes'] ?? 0),
            $this->toHourMinute($snapshot['statutory_excess_overtime_minutes'] ?? 0),
            $this->toHourMinute($snapshot['late_night_work_minutes'] ?? 0),
            $this->toHourMinute($snapshot['legal_holiday_work_minutes'] ?? 0),
            $this->toHourMinute($snapshot['prescribed_holiday_work_minutes'] ?? 0),
        ];
    }

    public function delimiter(): string
    {
        return "\t";
    }

    public function encoding(): string
    {
        return 'UTF-8';
    }

    public function fileExtension(): string
    {
        return 'tsv';
    }

    private function toHourMinute(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $remainder = $minutes % 60;

        return sprintf('%d:%02d', $hours, $remainder);
    }
}
