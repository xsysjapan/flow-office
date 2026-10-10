<?php

namespace Tests\Unit\Attendance;

use App\Domain\Attendance\Services\LeaveReleaseDayPolicy;
use App\Models\AttendanceBreak;
use App\Models\AttendanceDay;
use App\Models\AttendanceDayStatus;
use App\Models\AttendanceDailyCalculation;
use App\Models\AttendanceDaySource;
use App\Models\AttendanceLeaveSegment;
use App\Models\AttendanceWeeklyOvertimeAllocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * LeaveReleaseDayPolicy(論点15: 休暇の差戻し・取消で勤怠日を削除してよいかの判定)の検証。
 * 削除してよいのは「休暇のために勤怠が作った(source=leave)空の日」だけ。
 */
class LeaveReleaseDayPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function policy(): LeaveReleaseDayPolicy
    {
        return new LeaveReleaseDayPolicy;
    }

    /** 休暇のために作られた空の勤怠日(source=leave、実績なし)。 */
    private function leaveOnlyDay(array $overrides = []): AttendanceDay
    {
        $user = User::factory()->create();

        return AttendanceDay::query()->create($overrides + [
            'user_id' => $user->id,
            'work_date' => '2026-08-10',
            'status' => AttendanceDayStatus::NOT_STARTED,
            'source' => AttendanceDaySource::LEAVE,
            'utc_offset_minutes' => 540,
        ]);
    }

    public function test_a_leave_only_day_with_no_other_leave_is_removable(): void
    {
        $day = $this->leaveOnlyDay();

        $this->assertTrue($this->policy()->isRemovableAfterLeaveRelease($day, false));
    }

    public function test_a_day_with_another_active_leave_is_not_removable(): void
    {
        $day = $this->leaveOnlyDay();

        $this->assertFalse($this->policy()->isRemovableAfterLeaveRelease($day, true));
    }

    public function test_a_day_with_clock_in_or_clock_out_is_not_removable(): void
    {
        $withStart = $this->leaveOnlyDay(['actual_start_at' => '2026-08-10 09:00:00']);
        $withEnd = $this->leaveOnlyDay(['work_date' => '2026-08-11', 'actual_end_at' => '2026-08-11 18:00:00']);

        $this->assertFalse($this->policy()->isRemovableAfterLeaveRelease($withStart, false));
        $this->assertFalse($this->policy()->isRemovableAfterLeaveRelease($withEnd, false));
    }

    public function test_a_day_with_a_break_or_a_leave_segment_is_not_removable(): void
    {
        $withBreak = $this->leaveOnlyDay();
        AttendanceBreak::query()->create([
            'attendance_day_id' => $withBreak->id,
            'break_start_at' => '2026-08-10 12:00:00',
            'break_end_at' => '2026-08-10 13:00:00',
        ]);

        $withSegment = $this->leaveOnlyDay(['work_date' => '2026-08-11']);
        AttendanceLeaveSegment::query()->create([
            'attendance_day_id' => $withSegment->id,
            'start_at' => '2026-08-11 09:00:00',
            'end_at' => '2026-08-11 10:00:00',
            'note' => null,
        ]);

        $this->assertFalse($this->policy()->isRemovableAfterLeaveRelease($withBreak, false));
        $this->assertFalse($this->policy()->isRemovableAfterLeaveRelease($withSegment, false));
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function enteredInputCases(): array
    {
        return [
            'work_type(作業内容)がある' => [['work_type' => 'normal']],
            'work_location_type(勤務形態区分)がある' => [['work_location_type' => 'office']],
            '備考(note)がある' => [['note' => 'メモ']],
        ];
    }

    #[DataProvider('enteredInputCases')]
    public function test_a_day_with_entered_work_content_work_location_or_note_is_not_removable(array $overrides): void
    {
        $day = $this->leaveOnlyDay($overrides);

        $this->assertFalse($this->policy()->isRemovableAfterLeaveRelease($day, false));
    }

    public function test_an_empty_note_does_not_block_removal(): void
    {
        $day = $this->leaveOnlyDay(['note' => '']);

        $this->assertTrue($this->policy()->isRemovableAfterLeaveRelease($day, false));
    }

    public function test_a_manually_adjusted_day_is_not_removable(): void
    {
        $day = $this->leaveOnlyDay();
        AttendanceDailyCalculation::query()->create([
            'attendance_day_id' => $day->id,
            'work_minutes' => 0,
            'prescribed_work_minutes' => 0,
            'is_manually_adjusted' => true,
        ]);

        $this->assertFalse($this->policy()->isRemovableAfterLeaveRelease($day, false));
    }

    public function test_a_day_with_a_weekly_overtime_allocation_is_not_removable(): void
    {
        $day = $this->leaveOnlyDay();
        AttendanceWeeklyOvertimeAllocation::query()->create([
            'attendance_day_id' => $day->id,
            'week_start_date' => '2026-08-10',
            'allocated_by_user_id' => $day->user_id,
        ]);

        $this->assertFalse($this->policy()->isRemovableAfterLeaveRelease($day, false));
    }

    /** @return array<string, array{0: string}> */
    public static function nonLeaveSources(): array
    {
        return [
            '打刻(punch)から取り込んだ日' => [AttendanceDaySource::PUNCH],
            '日次編集(manual)の日' => [AttendanceDaySource::MANUAL],
            'リアルタイム打刻(live)の日' => [AttendanceDaySource::LIVE],
        ];
    }

    #[DataProvider('nonLeaveSources')]
    public function test_a_day_not_created_for_the_leave_is_never_removed(string $source): void
    {
        $day = $this->leaveOnlyDay(['source' => $source]);

        $this->assertFalse($this->policy()->isRemovableAfterLeaveRelease($day, false));
    }
}
