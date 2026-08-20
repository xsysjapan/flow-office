<?php

namespace App\Domain\Export\Services\AttendanceCsv;

use App\Models\AttendanceMonth;

/**
 * マネーフォワードクラウド給与B形式を模した専用フォーマット(勤怠項目名の変更に伴うV3)。
 * 平日/所定休日/法定休日別に34項目へ展開する。時間は小数点表記(例: 8.5)。カンマ区切り・UTF-8。
 *
 * 以下は現時点の勤怠計算エンジンに集計値・区分ロジックが無いため0固定
 * (将来対応。.claude/skills/attendance-calc-review参照):
 * - 遅刻回数・早退回数・遅刻時間・早退時間: `AttendanceCalculator`に遅刻・早退の判定ロジックが
 *   存在しない(別途検討)。
 * - 休憩時間(平日・所定外・深夜所定外・法定外・深夜法定外の5項目): `attendance_breaks`は
 *   区分を持たず休憩時間の内訳を計算していない。
 * - 代休取得日数・代休取得時間数: 今回のスコープでは集計しない。
 *
 * また、法定休日の労働は`AttendanceCalculator`が所定内/所定外に分解せず全額を法定外として
 * 扱う(法定休日に「所定」の概念が無いため)ので、「所定時間(法定休日)」「深夜所定時間(法定休日)」
 * 「所定外時間(法定休日)」は常に0になる。
 */
class MoneyForwardAttendanceCsvFormat implements AttendanceCsvFormat
{
    private const VERSION = '3';

    public function header(): array
    {
        return [
            'Version', '従業員番号', '氏名',
            '出勤日数（平日）', '出勤日数（所定休日）', '出勤日数（法定休日）', '欠勤日数（平日）',
            '遅刻回数（平日）', '早退回数（平日）',
            '所定時間（平日）', '休憩時間（平日）', '深夜所定時間（平日）', '深夜休憩時間（平日）',
            '所定外時間（平日）', '法定外時間（平日）', '深夜所定外時間（平日）', '深夜法定外時間（平日）',
            '所定外休憩時間（平日）', '深夜所定外休憩時間（平日）', '法定外休憩時間（平日）', '深夜法定外休憩時間（平日）',
            '遅刻時間（平日）', '早退時間（平日）',
            '所定時間（所定休日）', '深夜所定時間（所定休日）', '所定外時間（所定休日）', '法定外時間（所定休日）', '深夜法定外時間（所定休日）',
            '所定時間（法定休日）', '深夜所定時間（法定休日）', '所定外時間（法定休日）', '法定外時間（法定休日）', '深夜法定外時間（法定休日）',
            '有休取得日数', '有休取得時間数', '代休取得日数', '代休取得時間数',
        ];
    }

    public function row(AttendanceMonth $month, string $yearMonth): array
    {
        $snapshot = $month->snapshot_json ?? [];

        return [
            self::VERSION,
            $month->user_id,
            $month->user?->name,
            $snapshot['work_days_weekday'] ?? 0,
            $snapshot['work_days_prescribed_holiday'] ?? 0,
            $snapshot['work_days_legal_holiday'] ?? 0,
            $snapshot['absence_days'] ?? 0,
            0, // 遅刻回数（平日）
            0, // 早退回数（平日）
            $this->toDecimalHours($this->value($snapshot, 'weekday_prescribed_statutory_within_work_minutes', 'weekday_regular_work_minutes') + $this->value($snapshot, 'weekday_prescribed_statutory_excess_work_minutes')),
            0, // 休憩時間（平日）
            $this->toDecimalHours($snapshot['weekday_late_night_prescribed_work_minutes'] ?? 0),
            0, // 深夜休憩時間（平日）
            $this->toDecimalHours($this->value($snapshot, 'weekday_non_prescribed_statutory_within_work_minutes', 'weekday_statutory_within_overtime_minutes')),
            $this->toDecimalHours($this->value($snapshot, 'weekday_prescribed_statutory_excess_work_minutes') + $this->value($snapshot, 'weekday_non_prescribed_statutory_excess_work_minutes', 'weekday_statutory_excess_overtime_minutes')),
            $this->toDecimalHours($snapshot['weekday_late_night_statutory_within_overtime_minutes'] ?? 0),
            $this->toDecimalHours($snapshot['weekday_late_night_statutory_excess_overtime_minutes'] ?? 0),
            0, // 所定外休憩時間（平日）
            0, // 深夜所定外休憩時間（平日）
            0, // 法定外休憩時間（平日）
            0, // 深夜法定外休憩時間（平日）
            0, // 遅刻時間（平日）
            0, // 早退時間（平日）
            $this->toDecimalHours($this->value($snapshot, 'prescribed_holiday_prescribed_statutory_within_work_minutes', 'prescribed_holiday_work_minutes') + $this->value($snapshot, 'prescribed_holiday_prescribed_statutory_excess_work_minutes')),
            $this->toDecimalHours($snapshot['prescribed_holiday_late_night_prescribed_work_minutes'] ?? 0),
            $this->toDecimalHours($this->value($snapshot, 'prescribed_holiday_non_prescribed_statutory_within_work_minutes', 'prescribed_holiday_statutory_within_overtime_minutes')),
            $this->toDecimalHours($this->value($snapshot, 'prescribed_holiday_prescribed_statutory_excess_work_minutes') + $this->value($snapshot, 'prescribed_holiday_non_prescribed_statutory_excess_work_minutes', 'prescribed_holiday_statutory_excess_overtime_minutes')),
            $this->toDecimalHours($snapshot['prescribed_holiday_late_night_statutory_excess_overtime_minutes'] ?? 0),
            0, // 所定時間（法定休日）: 法定休日に「所定」の概念が無いため常に0
            0, // 深夜所定時間（法定休日）: 同上
            0, // 所定外時間（法定休日）: 同上
            $this->toDecimalHours($snapshot['legal_holiday_work_minutes'] ?? 0),
            $this->toDecimalHours($snapshot['late_night_legal_holiday_work_minutes'] ?? 0),
            $snapshot['paid_leave_days'] ?? 0,
            $this->toDecimalHours($snapshot['paid_leave_minutes'] ?? 0),
            0, // 代休取得日数
            0, // 代休取得時間数
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

    private function value(array $snapshot, string $key, ?string $legacyKey = null): int
    {
        if (array_key_exists($key, $snapshot)) {
            return (int) $snapshot[$key];
        }

        return $legacyKey === null ? 0 : (int) ($snapshot[$legacyKey] ?? 0);
    }
}
