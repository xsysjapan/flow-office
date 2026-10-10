<?php

namespace Tests\Feature\Leave;

use App\Domain\Attendance\Aggregates\AttendanceDayAggregate;
use App\Domain\Leave\Correction\LeaveCorrectionCandidateDetector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 休暇の補正候補の検出(leave:correction-report・LeaveCorrectionCandidateDetector)の検証。
 *
 * 候補の状態はイベント経由(勤怠日の集約)か、旧系統の stored_events の記録(直接の行の追加)で作る。
 * 読み取りモデル(投影)は直接書き込まない。検出は何も変更しないことも確かめる。
 */
class LeaveCorrectionReportTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = User::factory()->create();
    }

    public function test_candidate_1_finds_returned_requests_whose_usage_was_not_cancelled(): void
    {
        $legacyReturned = $this->uuid();
        $legacyReversed = $this->uuid();
        $legacyResubmitted = $this->uuid();
        $paidReturned = $this->uuid();
        $paidCancelled = $this->uuid();
        $specialCancelled = $this->uuid();
        $compensatoryReturned = $this->uuid();
        $paidMigratedReturned = $this->uuid();

        // 旧系統: 申請の集約に消化記録があり、差し戻されたまま(検出する)。
        $this->event($legacyReturned, 1, 'special_leave.usage_designated', ['usageType' => 'full']);
        $this->event($legacyReturned, 2, 'special_leave.request_returned', ['comment' => '差戻し']);

        // 消化記録が取り消された後の差戻し・再提出後は検出しない。
        $this->event($legacyReversed, 1, 'special_leave.usage_designated', ['usageType' => 'full']);
        $this->event($legacyReversed, 2, 'special_leave.usage_reversed', ['usageType' => 'full']);
        $this->event($legacyReversed, 3, 'special_leave.request_returned', ['comment' => '差戻し']);
        $this->event($legacyResubmitted, 1, 'special_leave.usage_designated', ['usageType' => 'full']);
        $this->event($legacyResubmitted, 2, 'special_leave.request_returned', ['comment' => '差戻し']);
        $this->event($legacyResubmitted, 3, 'special_leave.request_resubmitted', []);

        // 口座(新系統): 有給は申請IDを持ち、差戻し中の未取消は検出する。
        $this->event($paidReturned, 1, 'paid_leave_request.returned', ['returnedByUserId' => null]);
        $this->accountEvent(1, 'paid_leave_account.usage_designated', ['usageId' => 'U-PAID', 'paidLeaveRequestId' => $paidReturned]);

        // 口座の取消が記録済みなら検出しない(差戻しの取消は済み)。
        $this->event($specialCancelled, 1, 'special_leave.request_returned', ['comment' => '差戻し']);
        $this->accountEvent(2, 'special_leave_account.usage_designated', ['usageId' => 'U-SPECIAL', 'requestId' => $specialCancelled]);
        $this->accountEvent(3, 'special_leave_account.usage_cancelled', ['usageId' => 'U-SPECIAL']);

        // 代休(口座): 差戻し中の未取消は検出する。
        $this->event($compensatoryReturned, 1, 'compensatory_leave.request_returned', ['comment' => '差戻し']);
        $this->accountEvent(4, 'compensatory_leave_account.usage_designated', ['usageId' => 'U-COMP', 'requestId' => $compensatoryReturned]);

        // 引き継ぎ(migrated)で差戻しの状態を持つ有給は検出する。
        $this->event($paidMigratedReturned, 1, 'paid_leave_request.migrated', ['status' => 'returned', 'usageId' => 'U-MIG']);
        $this->accountEvent(5, 'paid_leave_account.usage_designated', ['usageId' => 'U-MIG', 'paidLeaveRequestId' => $paidMigratedReturned]);

        // 有給の取消済み(別の申請)は検出しない。
        $this->event($paidCancelled, 1, 'paid_leave_request.returned', ['returnedByUserId' => null]);
        $this->event($paidCancelled, 2, 'paid_leave_request.cancelled', ['cancelledByUserId' => null]);
        $this->accountEvent(6, 'paid_leave_account.usage_designated', ['usageId' => 'U-CANCEL', 'paidLeaveRequestId' => $paidCancelled]);

        $candidate = $this->candidate(LeaveCorrectionCandidateDetector::CANDIDATE_RETURNED_USAGE);

        $this->assertSame(4, $candidate['count']);
        $found = collect($candidate['items'])->mapWithKeys(fn (array $item) => [$item['request_id'] => $item['path']])->all();
        $this->assertSame([
            $legacyReturned => '旧系統(申請の集約に記録された消化記録)',
            $paidReturned => '口座(新系統)',
            $compensatoryReturned => '口座(新系統)',
            $paidMigratedReturned => '口座(新系統)',
        ], $found);
        $this->assertFalse(collect($candidate['items'])->contains('request_id', $legacyReversed));
        $this->assertFalse(collect($candidate['items'])->contains('request_id', $legacyResubmitted));
        $this->assertFalse(collect($candidate['items'])->contains('request_id', $specialCancelled));
        $this->assertFalse(collect($candidate['items'])->contains('request_id', $paidCancelled));

        $this->assertTrue($candidate['methods'][0]['recommended']);
        $this->assertFalse($candidate['methods'][1]['recommended']);
    }

    public function test_candidate_2_finds_days_whose_created_event_is_missing_and_not_legitimate_creation(): void
    {
        $directlyCreated = $this->uuid();
        $createdNormally = $this->uuid();
        $fromPunches = $this->uuid();
        $alreadyCorrected = $this->uuid();

        // 休暇の処理が直接作った日: 日次計算だけが記録されている。
        $this->event($directlyCreated, 1, 'attendance_day.calculated', ['calculation' => ['work_minutes' => 480], 'userId' => $this->employee->id, 'workDate' => '2026-10-05']);

        // 通常の作成経路(created)・打刻同期・補正済みは対象外。
        AttendanceDayAggregate::retrieve($createdNormally)
            ->create(
                userId: $this->employee->id,
                workDate: '2026-10-06',
                calendarEntryId: null,
                status: 'not_started',
                source: 'manual',
                utcOffsetMinutes: 540,
                actualStartAt: null,
                actualEndAt: null,
                workType: null,
                workLocationType: null,
                note: null,
                breaks: [],
                leaveSegments: [],
                reason: 'test',
                createdByUserId: $this->employee->id,
            )
            ->persist();
        $this->event($fromPunches, 1, 'attendance_day.synced_from_punches', ['userId' => $this->employee->id]);
        $this->event($alreadyCorrected, 1, 'attendance_day.calculated', ['calculation' => ['work_minutes' => 480]]);
        $this->event($alreadyCorrected, 2, 'attendance_day.corrected', ['correctionId' => 'C-1']);

        $candidate = $this->candidate(LeaveCorrectionCandidateDetector::CANDIDATE_CREATED_MISSING);

        $this->assertSame(1, $candidate['count']);
        $this->assertSame($directlyCreated, $candidate['items'][0]['attendance_day_id']);
        $this->assertSame('attendance_day.calculated', $candidate['items'][0]['first_event']);
        $this->assertFalse($candidate['items'][0]['row_exists']);
        $this->assertTrue($candidate['methods'][0]['recommended']);
    }

    public function test_candidate_3_finds_leave_values_in_created_and_edited_events(): void
    {
        $dayA = $this->uuid();
        $dayB = $this->uuid();
        $dayC = $this->uuid();

        $this->createDay($dayA, 'paid_leave_full', 'not_started');
        $this->createDay($dayB, 'normal', 'not_started');
        AttendanceDayAggregate::retrieve($dayB)
            ->edit(540, null, null, 'not_started', 'compensatory_leave_full', null, false, null, [], [], 'test', $this->employee->id)
            ->persist();
        $this->createDay($dayC, null, 'clocked_out');

        $candidate = $this->candidate(LeaveCorrectionCandidateDetector::CANDIDATE_LEAVE_VALUE_IN_EVENTS);

        $this->assertSame(2, $candidate['count']);
        $this->assertSame(
            [
                ['attendance_day.created', 'paid_leave_full'],
                ['attendance_day.edited', 'compensatory_leave_full'],
            ],
            collect($candidate['items'])->map(fn (array $item) => [$item['event_class'], $item['work_type']])->values()->all(),
        );
        $this->assertTrue($candidate['methods'][0]['recommended']);
    }

    public function test_candidate_4_finds_leave_values_and_full_day_statuses_left_on_the_days(): void
    {
        $withLeaveValue = $this->uuid();
        $fullDayWithoutActual = $this->uuid();
        $workedDay = $this->uuid();

        $this->createDay($withLeaveValue, 'paid_leave_full', 'not_started');
        $this->createDay($fullDayWithoutActual, null, 'clocked_out');
        $this->createDay($workedDay, null, 'clocked_out', '2026-10-07T09:00:00+09:00', '2026-10-07T18:00:00+09:00');

        $candidate = $this->candidate(LeaveCorrectionCandidateDetector::CANDIDATE_LEAVE_VALUE_ON_DAYS);

        $this->assertSame(2, $candidate['count']);
        $ids = collect($candidate['items'])->pluck('attendance_day_id')->all();
        $this->assertContains($withLeaveValue, $ids);
        $this->assertContains($fullDayWithoutActual, $ids);
        $this->assertNotContains($workedDay, $ids);
    }

    public function test_the_report_changes_nothing_and_prints_each_candidate(): void
    {
        $this->createDay($this->uuid(), 'paid_leave_full', 'not_started');
        $eventsBefore = DB::table('stored_events')->count();
        $daysBefore = DB::table('attendance_days')->count();

        $this->assertCount(4, app(LeaveCorrectionCandidateDetector::class)->detect());
        $this->artisan('leave:correction-report')
            ->expectsOutputToContain('(1) 差し戻された休暇の未取消の消化記録')
            ->expectsOutputToContain('(2) 休暇の処理が直接作った勤怠日')
            ->expectsOutputToContain('(3) 編集イベントに入った休暇値')
            ->expectsOutputToContain('(4) 勤怠日に残る休暇値')
            ->assertSuccessful();

        $this->assertSame($eventsBefore, DB::table('stored_events')->count());
        $this->assertSame($daysBefore, DB::table('attendance_days')->count());
    }

    /** @return array<string, mixed> */
    private function candidate(int $id): array
    {
        foreach (app(LeaveCorrectionCandidateDetector::class)->detect() as $candidate) {
            if ($candidate['id'] === $id) {
                return $candidate;
            }
        }

        $this->fail("候補 {$id} が見つかりません");
    }

    /** 旧系統の stored_events に行を追加する(集約ID=申請ID等。版は呼び出し側が連番で渡す)。 */
    private function event(string $aggregateUuid, int $version, string $eventClass, array $properties): void
    {
        DB::table('stored_events')->insert([
            'aggregate_uuid' => $aggregateUuid,
            'aggregate_version' => $version,
            'event_version' => 1,
            'event_class' => $eventClass,
            'event_properties' => json_encode($properties, JSON_THROW_ON_ERROR),
            'meta_data' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }

    /** 口座の集約(利用者ID単位)のイベントを追加する。版は利用者の口座の中で連番にする。 */
    private function accountEvent(int $version, string $eventClass, array $properties): void
    {
        $this->event($this->employee->id, $version, $eventClass, $properties + ['userId' => $this->employee->id]);
    }

    private function createDay(string $dayId, ?string $workType, string $status, ?string $actualStart = null, ?string $actualEnd = null): void
    {
        AttendanceDayAggregate::retrieve($dayId)
            ->create(
                userId: $this->employee->id,
                workDate: '2026-10-05',
                calendarEntryId: null,
                status: $status,
                source: 'manual',
                utcOffsetMinutes: 540,
                actualStartAt: $actualStart,
                actualEndAt: $actualEnd,
                workType: $workType,
                workLocationType: null,
                note: null,
                breaks: [],
                leaveSegments: [],
                reason: 'test',
                createdByUserId: $this->employee->id,
            )
            ->persist();
    }

    private function uuid(): string
    {
        return (string) Str::uuid();
    }
}
