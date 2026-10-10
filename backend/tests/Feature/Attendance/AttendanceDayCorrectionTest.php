<?php

namespace Tests\Feature\Attendance;

use App\Domain\Attendance\Aggregates\AttendanceDayAggregate;
use App\Domain\Attendance\Commands\CorrectAttendanceDay;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Models\AttendanceDay;
use App\Models\CompensatoryLeaveGrant;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 補正専用イベント attendance_day.corrected(変更セット論点12・仕様確定事項H)のシナリオテスト。
 *
 * - 補正Command(CorrectAttendanceDay)から、勤怠日・休憩・不就労区間・日次計算・週40時間配賦、出勤率ビュー、
 *   代休の休日出勤ビューまでが記録した状態どおりになること。
 * - 欠けた attendance_day.created の日(休暇の処理が直接作った日を模す)を、補正イベントで補完できること。
 * - 同じ補正IDは1度だけ記録すること。理由が無い補正は拒否されること。
 * - 補正後に全ReadModelを空にして event-sourcing:replay で再生成しても同じ状態になること。
 */
class AttendanceDayCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private const WORK_DATE = '2026-10-05';

    private const LATER_DATE = '2026-10-06';

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = User::factory()->create();
    }

    public function test_a_correction_replaces_the_stale_values_and_is_recorded_once(): void
    {
        $dayId = $this->uuid();
        $this->createStaleDay($dayId);

        $this->correct($dayId, 'C-1', [
            'note' => '補正後の備考',
            'workType' => null,
            'workLocationType' => 'office',
            'actualStartAt' => self::WORK_DATE.'T09:30:00+09:00',
            'breaks' => [['start' => self::WORK_DATE.'T12:00:00+09:00', 'end' => self::WORK_DATE.'T13:00:00+09:00']],
            'dailyCalculation' => $this->dailyRow(510),
            'weeklyOvertimeAllocation' => $this->weeklyRow(60),
        ]);

        $day = DB::table('attendance_days')->where('id', $dayId)->first();
        $this->assertSame('補正後の備考', $day->note);
        $this->assertNull($day->work_type, '休暇値の複写は補正で消える');
        $this->assertSame('office', $day->work_location_type);
        $this->assertSame('working_day', $day->day_classification);
        $this->assertSame('09:30:00', substr((string) $day->actual_start_at, 11));

        $this->assertCount(1, DB::table('attendance_breaks')->where('attendance_day_id', $dayId)->get());
        $this->assertCount(0, DB::table('attendance_leave_segments')->where('attendance_day_id', $dayId)->get());

        $calculation = DB::table('attendance_daily_calculations')->where('attendance_day_id', $dayId)->first();
        $this->assertSame(510, (int) $calculation->work_minutes);
        $this->assertFalse((bool) $calculation->is_manually_adjusted);

        $allocation = DB::table('attendance_weekly_overtime_allocations')->where('attendance_day_id', $dayId)->first();
        $this->assertSame(60, (int) $allocation->prescribed_minutes);

        // 同じ補正IDの再実行は記録しない(後から別の値を渡しても無視される)。
        $this->correct($dayId, 'C-1', ['note' => '二度目は記録されない', 'workType' => null]);

        $this->assertSame(1, DB::table('stored_events')->where('aggregate_uuid', $dayId)->where('event_class', 'attendance_day.corrected')->count());
        $this->assertSame('補正後の備考', DB::table('attendance_days')->where('id', $dayId)->value('note'));
    }

    public function test_a_correction_creates_the_day_whose_created_event_is_missing_and_updates_the_views(): void
    {
        $dayId = $this->uuid();
        // 休暇の処理が直接作った日を模す: created が無く、日次計算だけが記録されている(行は無い)。
        AttendanceDayAggregate::retrieve($dayId)
            ->calculate($this->calculation(480), $this->employee->id, self::LATER_DATE)
            ->persist();
        $this->assertNull(AttendanceDay::query()->find($dayId));

        $this->correct($dayId, 'C-MISSING', [
            'workDate' => self::LATER_DATE,
            'status' => 'clocked_out',
            'source' => 'manual',
            'actualStartAt' => self::LATER_DATE.'T09:00:00+09:00',
            'actualEndAt' => self::LATER_DATE.'T18:00:00+09:00',
            'dayClassification' => 'working_day',
            'dailyCalculation' => $this->dailyRow(480),
        ]);

        $day = AttendanceDay::query()->findOrFail($dayId);
        $this->assertSame('clocked_out', $day->status);
        $this->assertSame($this->employee->id, $day->user_id);
        $this->assertSame(480, (int) DB::table('attendance_daily_calculations')->where('attendance_day_id', $dayId)->value('work_minutes'));

        // 出勤率ビュー(休暇文脈): 勤怠日の出勤状態が補正イベントから作られる。
        $this->assertTrue((bool) DB::table('leave_attendance_rate_attendance_days')->where('id', $dayId)->value('clocked_out'));
        $this->assertTrue((bool) DB::table('leave_attendance_rate_days')
            ->where('user_id', $this->employee->id)
            ->whereDate('work_date', self::LATER_DATE)
            ->value('attended'));
    }

    public function test_a_correction_with_no_daily_calculation_removes_the_holiday_work_row(): void
    {
        $dayId = $this->uuid();
        $this->createStaleDay($dayId);
        $this->assertTrue(DB::table('compensatory_holiday_work_days')
            ->where('user_id', $this->employee->id)
            ->whereDate('work_date', self::WORK_DATE)
            ->exists());

        $this->correct($dayId, 'C-NOCALC', ['dailyCalculation' => null, 'weeklyOvertimeAllocation' => null]);

        $this->assertFalse(DB::table('attendance_daily_calculations')->where('attendance_day_id', $dayId)->exists());
        $this->assertFalse(DB::table('compensatory_holiday_work_days')
            ->where('user_id', $this->employee->id)
            ->whereDate('work_date', self::WORK_DATE)
            ->exists());
    }

    public function test_a_correction_without_a_reason_is_rejected_and_records_nothing(): void
    {
        $dayId = $this->uuid();
        $this->createStaleDay($dayId);
        $before = DB::table('stored_events')->count();

        $this->expectException(DomainRuleException::class);
        try {
            $this->correct($dayId, 'C-NOREASON', ['reason' => '  ']);
        } finally {
            $this->assertSame($before, DB::table('stored_events')->count());
        }
    }

    public function test_rebuilding_all_read_models_from_events_reproduces_the_corrected_state(): void
    {
        $dayId = $this->uuid();
        $missingId = $this->uuid();
        $this->createStaleDay($dayId);
        AttendanceDayAggregate::retrieve($missingId)
            ->calculate($this->calculation(480), $this->employee->id, self::LATER_DATE)
            ->persist();

        $this->correct($dayId, 'C-REBUILD', [
            'note' => '補正後',
            'workType' => null,
            'actualStartAt' => self::WORK_DATE.'T09:30:00+09:00',
            'breaks' => [['start' => self::WORK_DATE.'T12:00:00+09:00', 'end' => self::WORK_DATE.'T13:00:00+09:00']],
            'dailyCalculation' => $this->dailyRow(510),
            'weeklyOvertimeAllocation' => $this->weeklyRow(60),
        ]);
        $this->correct($missingId, 'C-REBUILD-MISSING', [
            'workDate' => self::LATER_DATE,
            'status' => 'clocked_out',
            'source' => 'manual',
            'actualStartAt' => self::LATER_DATE.'T09:00:00+09:00',
            'actualEndAt' => self::LATER_DATE.'T18:00:00+09:00',
            'dayClassification' => 'working_day',
            'dailyCalculation' => $this->dailyRow(480),
        ]);

        $before = $this->readModelState([$dayId, $missingId]);

        DB::table('leave_attendance_rate_attendance_days')->delete();
        DB::table('leave_attendance_rate_days')->delete();
        DB::table('compensatory_holiday_work_days')->delete();
        DB::table('attendance_weekly_overtime_allocations')->delete();
        DB::table('attendance_daily_calculations')->delete();
        DB::table('attendance_leave_segments')->delete();
        DB::table('attendance_breaks')->delete();
        DB::table('attendance_days')->delete();
        Artisan::call('event-sourcing:replay', ['--force' => true]);

        $this->assertSame($before, $this->readModelState([$dayId, $missingId]));
    }

    public function test_a_correction_that_makes_the_day_holiday_work_syncs_the_compensatory_grant(): void
    {
        $this->enableCompensatoryLeave();
        $dayId = $this->uuid();
        $this->createStaleDay($dayId);
        $this->assertSame(0, $this->grantCount());

        // 実労働が休日出勤として補正されると、代休の付与が下書きで同期される(代休口座の文脈が補正イベントに反応する)。
        $this->correct($dayId, 'C-HOLIDAY', [
            'dayClassification' => 'prescribed_holiday',
            'dailyCalculation' => $this->dailyRow(480),
        ]);

        $this->assertSame(1, $this->grantCount());
        $grant = CompensatoryLeaveGrant::query()->where('user_id', $this->employee->id)->firstOrFail();
        $this->assertSame('attendance', $grant->source);
        $this->assertSame('draft', $grant->status);
        $this->assertSame(self::WORK_DATE, $grant->work_date->toDateString());

        // 休日でない日として補正されると、同じ日の下書き付与は外れる。
        $this->correct($dayId, 'C-WORKDAY', [
            'dayClassification' => 'working_day',
            'dailyCalculation' => $this->dailyRow(480),
        ]);

        $this->assertSame(0, $this->grantCount());
    }

    public function test_a_correction_without_a_daily_calculation_removes_the_compensatory_grant(): void
    {
        $this->enableCompensatoryLeave();
        $dayId = $this->uuid();
        $this->createStaleDay($dayId);
        $this->correct($dayId, 'C-HOLIDAY-2', [
            'dayClassification' => 'legal_holiday',
            'dailyCalculation' => $this->dailyRow(480),
        ]);
        $this->assertSame(1, $this->grantCount());

        $this->correct($dayId, 'C-NOCALC-GRANT', ['dailyCalculation' => null, 'weeklyOvertimeAllocation' => null]);

        $this->assertSame(0, $this->grantCount());
    }

    private function enableCompensatoryLeave(): void
    {
        SystemSetting::current()->update([
            'compensatory_leave_enabled' => true,
            'compensatory_leave_unit' => 'daily',
        ]);
    }

    private function grantCount(): int
    {
        return CompensatoryLeaveGrant::query()->where('user_id', $this->employee->id)->count();
    }

    /**
     * 補正Commandを発行する。$overrides で既定値の一部を変える(既定は補正前後で同じ日・同じ利用者)。
     *
     * @param  array<string, mixed>  $overrides
     */
    private function correct(string $dayId, string $correctionId, array $overrides): void
    {
        $values = $overrides + [
            'workDate' => self::WORK_DATE,
            'status' => 'clocked_out',
            'source' => 'punch',
            'utcOffsetMinutes' => 540,
            'actualStartAt' => self::WORK_DATE.'T09:00:00+09:00',
            'actualEndAt' => self::WORK_DATE.'T18:00:00+09:00',
            'workType' => null,
            'workLocationType' => null,
            'note' => null,
            'dayClassification' => 'working_day',
            'breaks' => [],
            'leaveSegments' => [],
            'dailyCalculation' => $this->dailyRow(480),
            'weeklyOvertimeAllocation' => null,
            'reason' => '本番データ補正(テスト)',
        ];

        app(CommandBus::class)->dispatch(new CorrectAttendanceDay(
            attendanceDayId: $dayId,
            correctionId: $correctionId,
            userId: $this->employee->id,
            workDate: $values['workDate'],
            calendarEntryId: null,
            status: $values['status'],
            source: $values['source'],
            utcOffsetMinutes: $values['utcOffsetMinutes'],
            actualStartAt: $values['actualStartAt'],
            actualEndAt: $values['actualEndAt'],
            workType: $values['workType'],
            workLocationType: $values['workLocationType'],
            note: $values['note'],
            dayClassification: $values['dayClassification'],
            breaks: $values['breaks'],
            leaveSegments: $values['leaveSegments'],
            dailyCalculation: $values['dailyCalculation'],
            weeklyOvertimeAllocation: $values['weeklyOvertimeAllocation'],
            reason: $values['reason'],
            correctedByUserId: $this->employee->id,
        ));
    }

    /** 休暇の値・誤った備考・古い日次計算を持つ勤怠日を、通常のイベント(created・calculated)で作る。 */
    private function createStaleDay(string $dayId): void
    {
        AttendanceDayAggregate::retrieve($dayId)
            ->create(
                userId: $this->employee->id,
                workDate: self::WORK_DATE,
                calendarEntryId: null,
                status: 'clocked_out',
                source: 'punch',
                utcOffsetMinutes: 540,
                actualStartAt: self::WORK_DATE.'T09:00:00+09:00',
                actualEndAt: self::WORK_DATE.'T18:00:00+09:00',
                workType: 'paid_leave_full',
                workLocationType: null,
                note: '誤った備考',
                breaks: [],
                leaveSegments: [],
                reason: 'test',
                createdByUserId: $this->employee->id,
            )
            ->calculate($this->calculation(480), $this->employee->id, self::WORK_DATE)
            ->persist();
    }

    /** @return array<string, mixed> 日次計算のイベントのpayload(calculateへ渡す値。attendance_daily_calculationsの列に加えて日区分を持つ)。 */
    private function calculation(int $workMinutes): array
    {
        return $this->dailyRow($workMinutes) + ['day_classification' => 'working_day'];
    }

    /** @return array<string, mixed> attendance_daily_calculationsの列(補正イベントのdailyCalculationと同じ形)。 */
    private function dailyRow(int $workMinutes): array
    {
        return [
            'planned_work_minutes' => 480,
            'work_minutes' => $workMinutes,
            'deemed_work_minutes' => $workMinutes,
            'payroll_work_minutes' => $workMinutes,
            'prescribed_work_minutes' => min(480, $workMinutes),
            'statutory_within_overtime_minutes' => 0,
            'statutory_excess_overtime_minutes' => 0,
            'prescribed_statutory_within_work_minutes' => min(480, $workMinutes),
            'non_prescribed_statutory_within_work_minutes' => 0,
            'prescribed_statutory_excess_work_minutes' => 0,
            'non_prescribed_statutory_excess_work_minutes' => 0,
            'late_night_work_minutes' => 0,
            'late_night_prescribed_work_minutes' => 0,
            'late_night_statutory_within_overtime_minutes' => 0,
            'late_night_statutory_excess_overtime_minutes' => 0,
            'late_night_prescribed_statutory_within_work_minutes' => 0,
            'late_night_non_prescribed_statutory_within_work_minutes' => 0,
            'late_night_prescribed_statutory_excess_work_minutes' => 0,
            'late_night_non_prescribed_statutory_excess_work_minutes' => 0,
            'legal_holiday_work_minutes' => 0,
            'prescribed_holiday_work_minutes' => 0,
            'late_night_legal_holiday_work_minutes' => 0,
            'late_night_prescribed_holiday_work_minutes' => 0,
            'core_time_violation' => false,
            'absence_minutes' => 0,
            'special_leave_minutes' => 0,
            'paid_leave_days' => 0,
            'paid_leave_minutes' => 0,
            'special_leave_days' => 0,
            'is_manually_adjusted' => false,
            'adjusted_by_user_id' => null,
            'adjusted_at' => null,
        ];
    }

    /** @return array<string, mixed> attendance_weekly_overtime_allocationsの列(補正イベントの値と同じ形)。 */
    private function weeklyRow(int $prescribedMinutes): array
    {
        return [
            'week_start_date' => '2026-10-05',
            'prescribed_minutes' => $prescribedMinutes,
            'non_prescribed_minutes' => 0,
            'late_night_prescribed_minutes' => 0,
            'late_night_non_prescribed_minutes' => 0,
            'allocated_by_user_id' => $this->employee->id,
        ];
    }

    /**
     * 比較用の読み取りモデルの状態(IDとタイムスタンプは除く)。
     *
     * @param  list<string>  $dayIds
     * @return array<string, mixed>
     */
    private function readModelState(array $dayIds): array
    {
        $state = [];
        foreach ($dayIds as $dayId) {
            $state[$dayId] = [
                'day' => $this->stripped(DB::table('attendance_days')->where('id', $dayId)->first()),
                'breaks' => $this->rows(DB::table('attendance_breaks')->where('attendance_day_id', $dayId)->orderBy('break_start_at')),
                'leaveSegments' => $this->rows(DB::table('attendance_leave_segments')->where('attendance_day_id', $dayId)->orderBy('start_at')),
                'calculation' => $this->stripped(DB::table('attendance_daily_calculations')->where('attendance_day_id', $dayId)->first()),
                'allocation' => $this->stripped(DB::table('attendance_weekly_overtime_allocations')->where('attendance_day_id', $dayId)->first()),
            ];
        }

        $state['attendance_rate'] = $this->rows(DB::table('leave_attendance_rate_attendance_days')->orderBy('id'));
        $state['attendance_rate_days'] = $this->rows(DB::table('leave_attendance_rate_days')->where('user_id', $this->employee->id)->orderBy('work_date'));
        $state['holiday_work'] = $this->rows(DB::table('compensatory_holiday_work_days')->where('user_id', $this->employee->id)->orderBy('work_date'));

        return $state;
    }

    private function stripped(?object $row): ?array
    {
        if ($row === null) {
            return null;
        }

        return array_diff_key((array) $row, array_flip(['id', 'attendance_day_id', 'created_at', 'updated_at']));
    }

    /** @return list<array<string, mixed>> */
    private function rows(\Illuminate\Database\Query\Builder $query): array
    {
        return $query->get()->map(fn ($row) => $this->stripped($row))->all();
    }

    private function uuid(): string
    {
        return (string) Str::uuid();
    }
}
