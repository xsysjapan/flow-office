<?php

namespace Tests\Unit\Export;

use App\Domain\Export\Services\AttendanceExcelLeaveNote;
use App\Models\AttendanceDayLeave;
use App\Models\PaidLeaveType;
use PHPUnit\Framework\TestCase;

/**
 * 勤怠管理表(Excel)の備考に出す休暇の表記(AttendanceExcelLeaveNote)の単体テスト。DBは使わない。
 * 文言・並び順・申請中の付記・特別休暇の種別名の扱いを、休暇ビューの行の形で直接確かめる。
 */
class AttendanceExcelLeaveNoteTest extends TestCase
{
    /** @param array<string, mixed> $overrides */
    private function leave(array $overrides = []): array
    {
        return array_merge([
            'leave_kind' => AttendanceDayLeave::KIND_PAID,
            'leave_request_id' => 'req-1',
            'user_id' => 'user-1',
            'work_date' => '2026-08-10',
            'unit' => PaidLeaveType::FULL,
            'hours' => null,
            'minutes' => null,
            'special_leave_type_id' => null,
            'workflow_request_id' => 'wf-1',
            'request_status' => AttendanceDayLeave::STATUS_APPROVED,
        ], $overrides);
    }

    private function note(): AttendanceExcelLeaveNote
    {
        return new AttendanceExcelLeaveNote;
    }

    public function test_paid_leave_is_written_with_its_unit_for_every_unit(): void
    {
        $expected = [
            PaidLeaveType::FULL => '有給休暇(全休)',
            PaidLeaveType::AM_HALF => '有給休暇(午前半休)',
            PaidLeaveType::PM_HALF => '有給休暇(午後半休)',
            PaidLeaveType::HOURLY => '有給休暇(時間休)',
        ];

        foreach ($expected as $unit => $text) {
            $this->assertSame($text, $this->note()->format([$this->leave(['unit' => $unit])], []), $unit);
        }
    }

    public function test_compensatory_leave_is_written_with_its_unit_for_every_unit(): void
    {
        $expected = [
            PaidLeaveType::FULL => '代休(全休)',
            PaidLeaveType::AM_HALF => '代休(午前半休)',
            PaidLeaveType::PM_HALF => '代休(午後半休)',
            PaidLeaveType::HOURLY => '代休(時間休)',
        ];

        foreach ($expected as $unit => $text) {
            $leave = $this->leave(['leave_kind' => AttendanceDayLeave::KIND_COMPENSATORY, 'unit' => $unit]);
            $this->assertSame($text, $this->note()->format([$leave], []), $unit);
        }
    }

    public function test_special_leave_is_written_with_its_type_name_for_every_unit(): void
    {
        $names = [5 => '誕生日休暇'];
        $expected = [
            PaidLeaveType::FULL => '誕生日休暇(全休)',
            PaidLeaveType::AM_HALF => '誕生日休暇(午前半休)',
            PaidLeaveType::PM_HALF => '誕生日休暇(午後半休)',
            PaidLeaveType::HOURLY => '誕生日休暇(時間休)',
        ];

        foreach ($expected as $unit => $text) {
            $leave = $this->leave(['leave_kind' => AttendanceDayLeave::KIND_SPECIAL, 'unit' => $unit, 'special_leave_type_id' => 5]);
            $this->assertSame($text, $this->note()->format([$leave], $names), $unit);
        }
    }

    public function test_special_leave_without_a_type_id_or_with_an_unknown_type_is_written_as_special_leave(): void
    {
        $withoutId = $this->leave(['leave_kind' => AttendanceDayLeave::KIND_SPECIAL, 'special_leave_type_id' => null]);
        $unknown = $this->leave(['leave_kind' => AttendanceDayLeave::KIND_SPECIAL, 'special_leave_type_id' => 99]);

        $this->assertSame('特別休暇(全休)', $this->note()->format([$withoutId], [5 => '誕生日休暇']));
        $this->assertSame('特別休暇(全休)', $this->note()->format([$unknown], [5 => '誕生日休暇']));
        $this->assertSame('特別休暇(全休)', $this->note()->format([$unknown], []));
    }

    public function test_special_leave_with_an_empty_name_is_written_as_special_leave(): void
    {
        $leave = $this->leave(['leave_kind' => AttendanceDayLeave::KIND_SPECIAL, 'special_leave_type_id' => 5]);

        $this->assertSame('特別休暇(全休)', $this->note()->format([$leave], [5 => '']));
    }

    public function test_submitted_leave_gets_the_submitted_suffix_and_approved_leave_does_not(): void
    {
        $submitted = $this->leave([
            'leave_kind' => AttendanceDayLeave::KIND_COMPENSATORY,
            'request_status' => AttendanceDayLeave::STATUS_SUBMITTED,
        ]);
        $approved = $this->leave([
            'leave_kind' => AttendanceDayLeave::KIND_COMPENSATORY,
            'request_status' => AttendanceDayLeave::STATUS_APPROVED,
        ]);

        $this->assertSame('代休(全休・申請中)', $this->note()->format([$submitted], []));
        $this->assertSame('代休(全休)', $this->note()->format([$approved], []));
    }

    public function test_submitted_special_leave_uses_the_type_name_with_the_suffix(): void
    {
        $leave = $this->leave([
            'leave_kind' => AttendanceDayLeave::KIND_SPECIAL,
            'unit' => PaidLeaveType::AM_HALF,
            'special_leave_type_id' => 5,
            'request_status' => AttendanceDayLeave::STATUS_SUBMITTED,
        ]);

        $this->assertSame('誕生日休暇(午前半休・申請中)', $this->note()->format([$leave], [5 => '誕生日休暇']));
    }

    public function test_no_leave_gives_an_empty_string(): void
    {
        $this->assertSame('', $this->note()->format([], [5 => '誕生日休暇']));
    }

    public function test_am_half_paid_leave_and_pm_half_compensatory_leave_are_joined_in_unit_order_even_if_input_is_reversed(): void
    {
        $compensatory = $this->leave([
            'leave_kind' => AttendanceDayLeave::KIND_COMPENSATORY,
            'leave_request_id' => 'req-c',
            'unit' => PaidLeaveType::PM_HALF,
        ]);
        $paid = $this->leave([
            'leave_kind' => AttendanceDayLeave::KIND_PAID,
            'leave_request_id' => 'req-p',
            'unit' => PaidLeaveType::AM_HALF,
        ]);

        $this->assertSame('有給休暇(午前半休)・代休(午後半休)', $this->note()->format([$compensatory, $paid], []));
        $this->assertSame('有給休暇(午前半休)・代休(午後半休)', $this->note()->format([$paid, $compensatory], []));
    }

    public function test_full_day_is_listed_before_hourly_leave_regardless_of_input_order(): void
    {
        $hourly = $this->leave(['unit' => PaidLeaveType::HOURLY, 'leave_request_id' => 'req-h']);
        $full = $this->leave(['unit' => PaidLeaveType::FULL, 'leave_request_id' => 'req-f', 'leave_kind' => AttendanceDayLeave::KIND_COMPENSATORY]);

        $this->assertSame('代休(全休)・有給休暇(時間休)', $this->note()->format([$hourly, $full], []));
        $this->assertSame('代休(全休)・有給休暇(時間休)', $this->note()->format([$full, $hourly], []));
    }

    public function test_within_the_same_unit_the_order_is_paid_then_special_then_compensatory(): void
    {
        $compensatory = $this->leave(['leave_kind' => AttendanceDayLeave::KIND_COMPENSATORY, 'leave_request_id' => 'req-1']);
        $special = $this->leave([
            'leave_kind' => AttendanceDayLeave::KIND_SPECIAL, 'special_leave_type_id' => 5, 'leave_request_id' => 'req-2',
        ]);
        $paid = $this->leave(['leave_kind' => AttendanceDayLeave::KIND_PAID, 'leave_request_id' => 'req-3']);
        $names = [5 => '誕生日休暇'];

        $this->assertSame(
            '有給休暇(全休)・誕生日休暇(全休)・代休(全休)',
            $this->note()->format([$compensatory, $special, $paid], $names),
        );
        $this->assertSame(
            '有給休暇(全休)・誕生日休暇(全休)・代休(全休)',
            $this->note()->format([$paid, $compensatory, $special], $names),
        );
    }

    public function test_same_unit_and_kind_are_ordered_by_leave_request_id_ascending(): void
    {
        $names = [5 => '誕生日休暇', 6 => '慶弔休暇'];
        $birthday = $this->leave([
            'leave_kind' => AttendanceDayLeave::KIND_SPECIAL, 'special_leave_type_id' => 5, 'leave_request_id' => 'req-b',
        ]);
        $condolence = $this->leave([
            'leave_kind' => AttendanceDayLeave::KIND_SPECIAL, 'special_leave_type_id' => 6, 'leave_request_id' => 'req-a',
        ]);

        $this->assertSame('慶弔休暇(全休)・誕生日休暇(全休)', $this->note()->format([$birthday, $condolence], $names));
        $this->assertSame('慶弔休暇(全休)・誕生日休暇(全休)', $this->note()->format([$condolence, $birthday], $names));
    }

    public function test_identical_labels_are_not_merged(): void
    {
        $a = $this->leave(['leave_request_id' => 'req-a']);
        $b = $this->leave(['leave_request_id' => 'req-b']);

        $this->assertSame('有給休暇(全休)・有給休暇(全休)', $this->note()->format([$a, $b], []));
    }

    public function test_unknown_leave_kind_or_unit_is_skipped_without_an_exception(): void
    {
        $unknownKind = $this->leave(['leave_kind' => 'mystery']);
        $unknownUnit = $this->leave(['unit' => 'quarter', 'leave_request_id' => 'req-2']);
        $valid = $this->leave(['leave_request_id' => 'req-3', 'unit' => PaidLeaveType::AM_HALF]);

        $this->assertSame('', $this->note()->format([$unknownKind, $unknownUnit], []));
        $this->assertSame('有給休暇(午前半休)', $this->note()->format([$unknownKind, $valid, $unknownUnit], []));
    }
}
