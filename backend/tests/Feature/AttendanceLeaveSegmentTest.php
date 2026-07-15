<?php

namespace Tests\Feature;

use App\Models\AttendanceDailyCalculation;
use App\Models\AttendanceLeaveSegment;
use App\Models\EmployeeShiftAssignment;
use App\Models\PaidLeaveGrant;
use App\Models\User;
use App\Models\WorkCalendar;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * 欠勤・特別休暇の時間帯単位の処理(docs/07-usecases-attendance.md「不就労時間の処理区分」)。
 * 有給休暇(全休・半休・時間単位)は既存のPaidLeaveRequestTestの対象。
 */
class AttendanceLeaveSegmentTest extends TestCase
{
    use RefreshDatabase;

    private function createWorkingDayShift(User $user, string $date, int $prescribedDailyMinutes = 480): EmployeeShiftAssignment
    {
        $calendar = WorkCalendar::query()->create([
            'name' => '2026年度', 'fiscal_year' => 2026,
            'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
            'week_starts_on' => 1, 'status' => 'published',
        ]);
        $workStyle = WorkStyle::query()->create([
            'code' => 'standard-'.$user->id.'-'.$date, 'name' => '通常勤務', 'work_time_system' => 'fixed',
            'prescribed_daily_minutes' => $prescribedDailyMinutes, 'prescribed_weekly_minutes' => $prescribedDailyMinutes * 5,
            'default_start_time' => '09:00', 'default_end_time' => '18:00',
            'default_break_minutes' => 60, 'calendar_id' => $calendar->id, 'is_shift_based' => false,
        ]);

        return EmployeeShiftAssignment::query()->create([
            'user_id' => $user->id, 'work_date' => $date, 'work_style_id' => $workStyle->id,
            'day_type' => 'weekday', 'is_working_day' => true, 'is_legal_holiday' => false, 'is_company_holiday' => false,
            'planned_start_at' => "{$date} 09:00:00", 'planned_end_at' => "{$date} 18:00:00",
            'planned_break_minutes' => 60,
        ]);
    }

    /**
     * ケース3: 2時間遅刻し、有給を使わず欠勤扱いにする。実績(11:00〜18:00)の外側の
     * 区間なので労働時間には影響せず、欠勤時間(120分)だけが別途集計される。
     */
    public function test_a_late_arrival_treated_as_absence_does_not_reduce_recorded_work_time(): void
    {
        $employee = User::factory()->create();
        $this->createWorkingDayShift($employee, '2026-08-10');

        $dayId = $this->actingAs($employee)->postJson('/api/attendance/days', [
            'user_id' => $employee->id,
            'work_date' => '2026-08-10',
            'reason' => '登録',
        ])->assertCreated()->json('id');

        $response = $this->actingAs($employee)->putJson("/api/attendance/days/{$dayId}", [
            'actual_start_at' => '2026-08-10T11:00:00+09:00',
            'actual_end_at' => '2026-08-10T18:00:00+09:00',
            'breaks' => [['start' => '2026-08-10T12:00:00+09:00', 'end' => '2026-08-10T13:00:00+09:00']],
            'leave_segments' => [
                ['category' => 'absence', 'start' => '2026-08-10T09:00:00+09:00', 'end' => '2026-08-10T11:00:00+09:00', 'note' => '寝坊のため'],
            ],
            'reason' => '遅刻を欠勤扱いにする',
        ]);

        $response->assertOk();
        $calculation = $response->json('calculation');
        $this->assertSame(360, $calculation['work_minutes']); // 11:00-18:00 - 休憩60分
        $this->assertSame(120, $calculation['absence_minutes']);
        $this->assertSame(0, $calculation['special_leave_minutes']);
        $this->assertCount(1, $response->json('leave_segments'));
        $this->assertSame('absence', $response->json('leave_segments.0.category'));
    }

    /**
     * ケース5: 勤務途中に2時間の特別休暇(中抜け)を挟む。実績(9:00〜18:00)の内側の区間
     * なので、休憩と同様に労働時間・深夜時間から控除される。
     */
    public function test_a_special_leave_segment_in_the_middle_of_the_shift_reduces_work_minutes(): void
    {
        $employee = User::factory()->create();
        $this->createWorkingDayShift($employee, '2026-08-10');

        $dayId = $this->actingAs($employee)->postJson('/api/attendance/days', [
            'user_id' => $employee->id,
            'work_date' => '2026-08-10',
            'reason' => '登録',
        ])->assertCreated()->json('id');

        $response = $this->actingAs($employee)->putJson("/api/attendance/days/{$dayId}", [
            'actual_start_at' => '2026-08-10T09:00:00+09:00',
            'actual_end_at' => '2026-08-10T18:00:00+09:00',
            'breaks' => [['start' => '2026-08-10T12:00:00+09:00', 'end' => '2026-08-10T13:00:00+09:00']],
            'leave_segments' => [
                ['category' => 'special_leave', 'start' => '2026-08-10T13:00:00+09:00', 'end' => '2026-08-10T15:00:00+09:00', 'note' => '通院'],
            ],
            'reason' => '中抜けで通院',
        ]);

        $response->assertOk();
        $calculation = $response->json('calculation');
        // 9:00-18:00(9時間) - 休憩60分 - 特別休暇120分 = 360分
        $this->assertSame(360, $calculation['work_minutes']);
        $this->assertSame(0, $calculation['absence_minutes']);
        $this->assertSame(120, $calculation['special_leave_minutes']);
    }

    /**
     * ケース6: 終日出勤せず、午前3時間だけ有給、残り(午後)は欠勤として処理する。
     * 実績が無い(actual_start_at/actual_end_atともにnull)ため労働時間は0分。
     */
    public function test_a_full_day_absence_with_no_actual_clock_times_has_zero_work_minutes(): void
    {
        $employee = User::factory()->create();
        $this->createWorkingDayShift($employee, '2026-08-10');

        $response = $this->actingAs($employee)->postJson('/api/attendance/days', [
            'user_id' => $employee->id,
            'work_date' => '2026-08-10',
            'leave_segments' => [
                ['category' => 'absence', 'start' => '2026-08-10T13:00:00+09:00', 'end' => '2026-08-10T18:00:00+09:00', 'note' => null],
            ],
            'reason' => '終日欠勤(午後分)',
        ]);

        $response->assertCreated();
        $calculation = $response->json('calculation');
        $this->assertSame(0, $calculation['work_minutes']);
        $this->assertSame(300, $calculation['absence_minutes']);
    }

    public function test_an_unknown_leave_segment_category_is_rejected(): void
    {
        $employee = User::factory()->create();
        $this->createWorkingDayShift($employee, '2026-08-10');

        $response = $this->actingAs($employee)->postJson('/api/attendance/days', [
            'user_id' => $employee->id,
            'work_date' => '2026-08-10',
            'leave_segments' => [
                ['category' => 'unknown_category', 'start' => '2026-08-10T09:00:00+09:00', 'end' => '2026-08-10T18:00:00+09:00', 'note' => null],
            ],
            'reason' => '不正な区分のテスト',
        ]);

        $response->assertStatus(422);
    }

    public function test_a_leave_segment_with_end_before_start_is_rejected(): void
    {
        $employee = User::factory()->create();
        $this->createWorkingDayShift($employee, '2026-08-10');

        $response = $this->actingAs($employee)->postJson('/api/attendance/days', [
            'user_id' => $employee->id,
            'work_date' => '2026-08-10',
            'leave_segments' => [
                ['category' => 'absence', 'start' => '2026-08-10T18:00:00+09:00', 'end' => '2026-08-10T09:00:00+09:00', 'note' => null],
            ],
            'reason' => '開始終了逆転のテスト',
        ]);

        $response->assertStatus(422);
    }

    /**
     * 欠勤・特別休暇の区間同士が重なっていると、それぞれの時間が二重に合算され
     * absence_minutes/special_leave_minutesが過大集計されてしまうため許可しない。
     */
    public function test_overlapping_leave_segments_are_rejected(): void
    {
        $employee = User::factory()->create();
        $this->createWorkingDayShift($employee, '2026-08-10');

        $response = $this->actingAs($employee)->postJson('/api/attendance/days', [
            'user_id' => $employee->id,
            'work_date' => '2026-08-10',
            'leave_segments' => [
                ['category' => 'absence', 'start' => '2026-08-10T09:00:00+09:00', 'end' => '2026-08-10T12:00:00+09:00', 'note' => null],
                ['category' => 'special_leave', 'start' => '2026-08-10T11:00:00+09:00', 'end' => '2026-08-10T13:00:00+09:00', 'note' => null],
            ],
            'reason' => '区間重複のテスト',
        ]);

        $response->assertStatus(422);
    }

    /**
     * 欠勤・特別休暇の区間が休憩と重なっていると、同じ時間帯が休憩・欠勤の両方から
     * 労働時間・深夜時間に対して控除され、二重控除になってしまうため許可しない。
     */
    public function test_a_leave_segment_overlapping_a_break_is_rejected(): void
    {
        $employee = User::factory()->create();
        $this->createWorkingDayShift($employee, '2026-08-10');

        $dayId = $this->actingAs($employee)->postJson('/api/attendance/days', [
            'user_id' => $employee->id,
            'work_date' => '2026-08-10',
            'reason' => '登録',
        ])->assertCreated()->json('id');

        $response = $this->actingAs($employee)->putJson("/api/attendance/days/{$dayId}", [
            'actual_start_at' => '2026-08-10T09:00:00+09:00',
            'actual_end_at' => '2026-08-10T18:00:00+09:00',
            'breaks' => [['start' => '2026-08-10T12:00:00+09:00', 'end' => '2026-08-10T13:00:00+09:00']],
            'leave_segments' => [
                ['category' => 'special_leave', 'start' => '2026-08-10T12:30:00+09:00', 'end' => '2026-08-10T14:00:00+09:00', 'note' => null],
            ],
            'reason' => '休憩と重複するテスト',
        ]);

        $response->assertStatus(422);
    }

    /**
     * re-editing the day replaces the leave segments wholesale (attendance_breaksと同じ扱い)。
     */
    public function test_re_editing_the_day_replaces_leave_segments(): void
    {
        $employee = User::factory()->create();
        $this->createWorkingDayShift($employee, '2026-08-10');

        $dayId = $this->actingAs($employee)->postJson('/api/attendance/days', [
            'user_id' => $employee->id,
            'work_date' => '2026-08-10',
            'leave_segments' => [
                ['category' => 'absence', 'start' => '2026-08-10T09:00:00+09:00', 'end' => '2026-08-10T18:00:00+09:00', 'note' => null],
            ],
            'reason' => '終日欠勤',
        ])->assertCreated()->json('id');

        $response = $this->actingAs($employee)->putJson("/api/attendance/days/{$dayId}", [
            'leave_segments' => [],
            'reason' => '出勤できることになったので取消',
        ]);

        $response->assertOk();
        $this->assertSame(0, $response->json('calculation.absence_minutes'));
        $this->assertCount(0, $response->json('leave_segments'));
    }

    /**
     * 時間単位有給(既存のUC-P003/P004)の承認は、日次集計のpaid_leave_minutesに反映される。
     * 全休・半休の承認はpaid_leave_daysに反映される。
     */
    public function test_hourly_and_full_day_paid_leave_are_reflected_in_the_daily_calculation(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $this->createWorkingDayShift($employee, '2026-08-10');
        $this->createWorkingDayShift($employee, '2026-08-11');

        PaidLeaveGrant::query()->create([
            'user_id' => $employee->id, 'granted_on' => '2025-07-01', 'expires_on' => '2027-06-30',
            'granted_days' => 10, 'used_days' => 0, 'remaining_days' => 10,
        ]);

        $hourlyRequestId = $this->actingAs($employee)->postJson('/api/paid-leave/requests', [
            'target_date' => '2026-08-10',
            'leave_type' => 'hourly',
            'hours' => 2,
            'approver_user_id' => $approver->id,
        ])->assertCreated()->json('id');
        $this->actingAs($approver)->postJson("/api/paid-leave/requests/{$hourlyRequestId}/approve")->assertOk();

        $fullRequestId = $this->actingAs($employee)->postJson('/api/paid-leave/requests', [
            'target_date' => '2026-08-11',
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
        ])->assertCreated()->json('id');
        $this->actingAs($approver)->postJson("/api/paid-leave/requests/{$fullRequestId}/approve")->assertOk();

        $days = $this->actingAs($employee)->getJson('/api/attendance/week?start_date=2026-08-10')->json();
        $dayByDate = collect($days)->keyBy('work_date');

        $this->assertEquals(120, $dayByDate['2026-08-10']['calculation']['paid_leave_minutes']);
        $this->assertEquals(0, $dayByDate['2026-08-10']['calculation']['paid_leave_days']);
        $this->assertEquals(1.0, $dayByDate['2026-08-11']['calculation']['paid_leave_days']);
        $this->assertEquals(0, $dayByDate['2026-08-11']['calculation']['paid_leave_minutes']);
    }

    public function test_monthly_category_totals_include_absence_paid_leave_and_special_leave_summaries(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $this->createWorkingDayShift($employee, '2026-08-10');
        $this->createWorkingDayShift($employee, '2026-08-11');
        $this->createWorkingDayShift($employee, '2026-08-12');

        // 8/10: 終日欠勤(実績なし)。
        $this->actingAs($employee)->postJson('/api/attendance/days', [
            'user_id' => $employee->id,
            'work_date' => '2026-08-10',
            'leave_segments' => [
                ['category' => 'absence', 'start' => '2026-08-10T09:00:00+09:00', 'end' => '2026-08-10T18:00:00+09:00', 'note' => null],
            ],
            'reason' => '終日欠勤',
        ])->assertCreated();

        // 8/11: 全休の有給。
        PaidLeaveGrant::query()->create([
            'user_id' => $employee->id, 'granted_on' => '2025-07-01', 'expires_on' => '2027-06-30',
            'granted_days' => 10, 'used_days' => 0, 'remaining_days' => 10,
        ]);
        $requestId = $this->actingAs($employee)->postJson('/api/paid-leave/requests', [
            'target_date' => '2026-08-11',
            'leave_type' => 'full',
            'approver_user_id' => $approver->id,
        ])->assertCreated()->json('id');
        $this->actingAs($approver)->postJson("/api/paid-leave/requests/{$requestId}/approve")->assertOk();

        // 8/12: 終日特別休暇(実績なし)。
        $this->actingAs($employee)->postJson('/api/attendance/days', [
            'user_id' => $employee->id,
            'work_date' => '2026-08-12',
            'leave_segments' => [
                ['category' => 'special_leave', 'start' => '2026-08-12T09:00:00+09:00', 'end' => '2026-08-12T18:00:00+09:00', 'note' => null],
            ],
            'reason' => '終日特別休暇',
        ])->assertCreated();

        $monthResponse = $this->actingAs($employee)->getJson('/api/attendance/months/2026-08');
        $monthResponse->assertOk();
        $totals = $monthResponse->json('monthly_calculation_totals');

        $this->assertSame(1, $totals['absence_days']);
        $this->assertSame(540, $totals['absence_minutes']); // 欠勤区間そのものの時間(9:00-18:00、休憩の控除はしない)
        $this->assertEquals(1.0, $totals['paid_leave_days']);
        $this->assertSame(1, $totals['special_leave_days']);
        $this->assertSame(540, $totals['special_leave_minutes']);
    }

    /**
     * 夜勤(21:00〜翌05:00)の途中に2時間の特別休暇を挟んだ場合、深夜時間帯
     * (22:00〜05:00)と重なる分だけ深夜労働時間からも控除されることを確認する
     * (休憩と同じ扱い。二重減算や符号ミスがないかのケース)。
     */
    public function test_a_special_leave_segment_overlapping_the_late_night_window_reduces_late_night_minutes(): void
    {
        $employee = User::factory()->create();
        $dateString = '2026-08-10';
        $nextDateString = '2026-08-11';

        $calendar = WorkCalendar::query()->create([
            'name' => '2026年度', 'fiscal_year' => 2026,
            'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31',
            'week_starts_on' => 1, 'status' => 'published',
        ]);
        $workStyle = WorkStyle::query()->create([
            'code' => 'night-shift', 'name' => '夜勤', 'work_time_system' => 'fixed',
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_start_time' => '21:00', 'default_end_time' => '05:00',
            'default_break_minutes' => 0, 'calendar_id' => $calendar->id, 'is_shift_based' => false,
        ]);
        EmployeeShiftAssignment::query()->create([
            'user_id' => $employee->id, 'work_date' => $dateString, 'work_style_id' => $workStyle->id,
            'day_type' => 'weekday', 'is_working_day' => true, 'is_legal_holiday' => false, 'is_company_holiday' => false,
            'planned_start_at' => "{$dateString} 21:00:00", 'planned_end_at' => "{$nextDateString} 05:00:00",
            'planned_break_minutes' => 0,
        ]);

        $dayId = $this->actingAs($employee)->postJson('/api/attendance/days', [
            'user_id' => $employee->id,
            'work_date' => $dateString,
            'reason' => '登録',
        ])->assertCreated()->json('id');

        $response = $this->actingAs($employee)->putJson("/api/attendance/days/{$dayId}", [
            'actual_start_at' => "{$dateString}T21:00:00+09:00",
            'actual_end_at' => "{$nextDateString}T05:00:00+09:00",
            'breaks' => [],
            'leave_segments' => [
                ['category' => 'special_leave', 'start' => "{$dateString}T23:00:00+09:00", 'end' => "{$nextDateString}T01:00:00+09:00", 'note' => null],
            ],
            'reason' => '夜勤中に特別休暇',
        ]);

        $response->assertOk();
        $calculation = $response->json('calculation');
        // 21:00〜翌05:00(480分) - 特別休暇120分 = 360分。
        $this->assertSame(360, $calculation['work_minutes']);
        $this->assertSame(120, $calculation['special_leave_minutes']);
        // 深夜帯(22:00〜翌05:00)420分のうち、特別休暇と重なる120分(23:00〜翌01:00は深夜帯に
        // 完全に含まれる)を控除して300分。
        $this->assertSame(300, $calculation['late_night_work_minutes']);
        // 3区分(所定/法定内残業/法定外残業)の合計はlate_night_work_minutesに一致する必要がある
        // (深夜時間帯と重なる欠勤・特別休暇の区間を境界計算からも除外できているかの確認)。
        $this->assertSame(
            $calculation['late_night_work_minutes'],
            $calculation['late_night_prescribed_work_minutes']
                + $calculation['late_night_statutory_within_overtime_minutes']
                + $calculation['late_night_statutory_excess_overtime_minutes'],
        );
    }

    /**
     * 再編集のたびに欠勤・特別休暇区間の内容が最新の状態に更新され、`projections:rebuild`で
     * `attendance_daily_calculations`を再生成しても同じ状態を再現できることを確認する
     * (docs/03-architecture.md 3.2「Projectionは再生成可能な派生データ」)。
     */
    public function test_projections_rebuild_reproduces_the_calculation_after_multiple_edits(): void
    {
        $employee = User::factory()->create();
        $this->createWorkingDayShift($employee, '2026-08-10');

        $dayId = $this->actingAs($employee)->postJson('/api/attendance/days', [
            'user_id' => $employee->id,
            'work_date' => '2026-08-10',
            'reason' => '登録',
        ])->assertCreated()->json('id');

        // 1回目の編集: 遅刻を欠勤扱いにする。
        $this->actingAs($employee)->putJson("/api/attendance/days/{$dayId}", [
            'actual_start_at' => '2026-08-10T11:00:00+09:00',
            'actual_end_at' => '2026-08-10T18:00:00+09:00',
            'breaks' => [['start' => '2026-08-10T12:00:00+09:00', 'end' => '2026-08-10T13:00:00+09:00']],
            'leave_segments' => [
                ['category' => 'absence', 'start' => '2026-08-10T09:00:00+09:00', 'end' => '2026-08-10T11:00:00+09:00', 'note' => '寝坊'],
            ],
            'reason' => '遅刻を欠勤扱いにする',
        ])->assertOk();

        // 2回目の編集: 通院の証明が出たため特別休暇に変更する(区間を入れ替え)。
        $finalResponse = $this->actingAs($employee)->putJson("/api/attendance/days/{$dayId}", [
            'actual_start_at' => '2026-08-10T11:00:00+09:00',
            'actual_end_at' => '2026-08-10T18:00:00+09:00',
            'breaks' => [['start' => '2026-08-10T12:00:00+09:00', 'end' => '2026-08-10T13:00:00+09:00']],
            'leave_segments' => [
                ['category' => 'special_leave', 'start' => '2026-08-10T09:00:00+09:00', 'end' => '2026-08-10T11:00:00+09:00', 'note' => '通院のため'],
            ],
            'reason' => '欠勤から特別休暇へ変更',
        ]);
        $finalResponse->assertOk();
        $this->assertSame(0, $finalResponse->json('calculation.absence_minutes'));
        $this->assertSame(120, $finalResponse->json('calculation.special_leave_minutes'));

        $before = AttendanceDailyCalculation::query()->where('attendance_day_id', $dayId)->first()->toArray();

        Artisan::call('projections:rebuild', ['projector' => 'AttendanceDailyCalculationProjector']);

        $after = AttendanceDailyCalculation::query()->where('attendance_day_id', $dayId)->first()->toArray();

        unset($before['updated_at'], $after['updated_at']);
        $this->assertSame($before, $after);
    }

    /**
     * UC-A015: 日次勤怠を削除すると、attendance_leave_segmentsも外部キーのcascadeOnDeleteで
     * 併せて削除される(attendance_breaks/attendance_daily_calculationsと同じ扱い)。
     */
    public function test_deleting_the_day_cascades_to_its_leave_segments(): void
    {
        $employee = User::factory()->create();
        $this->createWorkingDayShift($employee, '2026-08-10');

        $dayId = $this->actingAs($employee)->postJson('/api/attendance/days', [
            'user_id' => $employee->id,
            'work_date' => '2026-08-10',
            'leave_segments' => [
                ['category' => 'absence', 'start' => '2026-08-10T09:00:00+09:00', 'end' => '2026-08-10T11:00:00+09:00', 'note' => null],
            ],
            'reason' => '登録',
        ])->assertCreated()->json('id');

        $this->assertSame(1, AttendanceLeaveSegment::query()->where('attendance_day_id', $dayId)->count());

        $this->actingAs($employee)->deleteJson("/api/attendance/days/{$dayId}", ['reason' => '削除テスト'])->assertOk();

        $this->assertSame(0, AttendanceLeaveSegment::query()->where('attendance_day_id', $dayId)->count());
    }
}
