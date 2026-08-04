<?php

namespace App\Domain\Export\Services\AttendanceCsv;

use App\Models\AttendanceMonth;

/**
 * マネーフォワードクラウド給与B形式を模した専用フォーマット。
 * 「出勤日数」「欠勤日数」「遅刻早退日数」はsnapshot_jsonに集計値が無いため0固定
 * (正確な日数集計値が必要になった場合は将来対応)。時間は小数点表記(例: 8.5)。
 * カンマ区切り・UTF-8。
 */
class MoneyForwardAttendanceCsvFormat implements AttendanceCsvFormat
{
    private const VERSION = '2';

    public function header(): array
    {
        return [
            'Version', '従業員番号', '氏名', '出勤日数', '欠勤日数', '遅刻早退日数',
            '所定労働時間', '残業時間(法定外・平日)', '深夜法定外時間(平日)', '法定外(法定休日)', '深夜労働時間',
        ];
    }

    public function row(AttendanceMonth $month, string $yearMonth): array
    {
        $snapshot = $month->snapshot_json ?? [];

        return [
            self::VERSION,
            $month->user_id,
            $month->user?->name,
            0,
            0,
            0,
            $this->toDecimalHours($snapshot['prescribed_work_minutes'] ?? 0),
            $this->toDecimalHours($snapshot['statutory_excess_overtime_minutes'] ?? 0),
            $this->toDecimalHours($snapshot['late_night_statutory_excess_overtime_minutes'] ?? 0),
            $this->toDecimalHours($snapshot['legal_holiday_work_minutes'] ?? 0),
            $this->toDecimalHours($snapshot['late_night_work_minutes'] ?? 0),
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

    private function toDecimalHours(int $minutes): float
    {
        return round($minutes / 60, 2);
    }
}
