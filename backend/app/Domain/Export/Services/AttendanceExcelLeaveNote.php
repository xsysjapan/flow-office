<?php

namespace App\Domain\Export\Services;

use App\Models\AttendanceDayLeave;
use App\Models\PaidLeaveType;

/**
 * 勤怠管理表(Excel)の日別の備考に出す休暇の表記を組み立てる。DBを読まない。
 * 入力は勤怠の休暇ビュー(AttendanceDayLeaves)の行。文言は frontend/src/utils/statusLabels.ts と同じにする。
 */
final class AttendanceExcelLeaveNote
{
    private const SEPARATOR = '・';

    private const SUBMITTED_SUFFIX = '・申請中';

    /** 並び順: 単位(全休→午前半休→午後半休→時間休)。 */
    private const UNIT_LABELS = [
        PaidLeaveType::FULL => '全休',
        PaidLeaveType::AM_HALF => '午前半休',
        PaidLeaveType::PM_HALF => '午後半休',
        PaidLeaveType::HOURLY => '時間休',
    ];

    /** 並び順: 種類(有給→特別休暇→代休)。 */
    private const KIND_ORDER = [
        AttendanceDayLeave::KIND_PAID,
        AttendanceDayLeave::KIND_SPECIAL,
        AttendanceDayLeave::KIND_COMPENSATORY,
    ];

    private const FALLBACK_SPECIAL_LABEL = '特別休暇';

    /**
     * その日の休暇を「{種類}({単位})」で「・」連結して返す。休暇が無ければ空文字。
     * 未知の leave_kind・unit の行は出さない。
     *
     * @param  list<array<string, mixed>>  $leaves  その日の休暇の行(AttendanceDayLeaves の戻り値の形)
     * @param  array<int, string>  $specialLeaveTypeNames  special_leave_type_id => 種別名
     */
    public function format(array $leaves, array $specialLeaveTypeNames): string
    {
        $rows = [];
        foreach ($leaves as $leave) {
            $unit = $leave['unit'] ?? null;
            $kind = $leave['leave_kind'] ?? null;
            if (! array_key_exists($unit, self::UNIT_LABELS) || ! in_array($kind, self::KIND_ORDER, true)) {
                continue;
            }

            $rows[] = [
                'unit' => $unit,
                'kindOrder' => array_search($kind, self::KIND_ORDER, true),
                'requestId' => (string) ($leave['leave_request_id'] ?? ''),
                'label' => $this->label($leave, $specialLeaveTypeNames),
            ];
        }

        usort($rows, function (array $a, array $b): int {
            $unitOrder = array_search($a['unit'], array_keys(self::UNIT_LABELS), true)
                <=> array_search($b['unit'], array_keys(self::UNIT_LABELS), true);
            if ($unitOrder !== 0) {
                return $unitOrder;
            }

            return $a['kindOrder'] <=> $b['kindOrder'] ?: strcmp($a['requestId'], $b['requestId']);
        });

        return implode(self::SEPARATOR, array_column($rows, 'label'));
    }

    /**
     * @param  array<string, mixed>  $leave
     * @param  array<int, string>  $specialLeaveTypeNames
     */
    private function label(array $leave, array $specialLeaveTypeNames): string
    {
        $kindLabel = match ($leave['leave_kind']) {
            AttendanceDayLeave::KIND_PAID => '有給休暇',
            AttendanceDayLeave::KIND_SPECIAL => $this->specialLeaveName($leave['special_leave_type_id'] ?? null, $specialLeaveTypeNames),
            AttendanceDayLeave::KIND_COMPENSATORY => '代休',
        };
        $suffix = ($leave['request_status'] ?? null) === AttendanceDayLeave::STATUS_SUBMITTED ? self::SUBMITTED_SUFFIX : '';

        return $kindLabel.'('.self::UNIT_LABELS[$leave['unit']].$suffix.')';
    }

    /** @param array<int, string> $specialLeaveTypeNames */
    private function specialLeaveName(mixed $specialLeaveTypeId, array $specialLeaveTypeNames): string
    {
        if ($specialLeaveTypeId === null) {
            return self::FALLBACK_SPECIAL_LABEL;
        }

        $name = $specialLeaveTypeNames[(int) $specialLeaveTypeId] ?? null;

        return is_string($name) && $name !== '' ? $name : self::FALLBACK_SPECIAL_LABEL;
    }
}
