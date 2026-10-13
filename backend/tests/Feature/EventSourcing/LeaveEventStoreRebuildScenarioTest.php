<?php

namespace Tests\Feature\EventSourcing;

use App\Models\SpecialLeaveType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 休暇の stored_events の作り直し(leave:rebuild-event-store)のシナリオテスト(論点18)。
 * 旧系統の生の行を stored_events に insert → 試し実行 → --apply → --swap → 休暇・勤怠のReadModelを空にして
 * 全Projectorを replay → 申請の状態・消化記録・残数・勤怠日を確認する(UI非依存)。
 * 変換規則の判定は tests/Unit/EventSourcing/Rebuild/ にある。
 */
class LeaveEventStoreRebuildScenarioTest extends TestCase
{
    use RefreshDatabase;

    private const AT = '2026-09-01 10:00:00';

    /** @var array<string, int> */
    private array $versions = [];

    private int $nextId = 1;

    public function test_cutover_era_paid_requests_are_rebuilt_with_the_request_stream_and_replay_to_the_same_state(): void
    {
        $user = User::factory()->create();
        $approver = User::factory()->create();
        $grantId = (string) Str::uuid();
        [$r1, $w1, $u1] = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];
        [$r2, $w2, $u2] = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];

        $this->insertLegacyEvent($user->id, 'paid_leave_account.grant_created', ['grantId' => $grantId, 'grantedOn' => '2026-04-01', 'expiresOn' => '2027-03-31', 'grantedDays' => 10.0, 'grantReason' => null, 'source' => 'manual']);
        // 承認済み
        $this->insertLegacyEvent($w1, 'workflow_request.drafted', $this->drafted($user->id, $approver->id, $r1));
        $this->insertLegacyEvent($user->id, 'paid_leave_account.usage_designated', $this->designated($u1, $w1, $r1, '2026-09-10', $approver->id));
        $this->insertLegacyEvent($w1, 'workflow_request.submitted', ['approverUserId' => $approver->id, 'submittedByUserId' => $user->id]);
        $this->insertLegacyEvent($user->id, 'paid_leave_account.usage_confirmed', ['usageId' => $u1, 'confirmedByUserId' => $approver->id]);
        $this->insertLegacyEvent($user->id, 'paid_leave_account.usage_allocated', ['usageId' => $u1, 'grantId' => $grantId, 'allocatedDays' => 1.0]);
        $this->insertLegacyEvent($w1, 'workflow_request.approved', ['approvedByUserId' => $approver->id]);
        // 差戻し(口座イベントは無く、workflow_request.returned だけ)
        $this->insertLegacyEvent($w2, 'workflow_request.drafted', $this->drafted($user->id, $approver->id, $r2));
        $this->insertLegacyEvent($user->id, 'paid_leave_account.usage_designated', $this->designated($u2, $w2, $r2, '2026-09-11', $approver->id));
        $this->insertLegacyEvent($w2, 'workflow_request.submitted', ['approverUserId' => $approver->id, 'submittedByUserId' => $user->id]);
        $this->insertLegacyEvent($w2, 'workflow_request.returned', ['returnedByUserId' => $approver->id, 'comment' => '日付を直して']);

        $this->rebuildAndSwap();

        $classes = DB::table('stored_events')->orderBy('id')->pluck('event_class')->all();
        $this->assertContains('paid_leave_request.requested', $classes);
        $this->assertContains('paid_leave_request.returned', $classes);
        $this->assertSame(1, DB::table('stored_events')->where('event_class', 'paid_leave_account.usage_cancelled')->count());

        $this->replayReadModels();

        $this->assertSame('approved', DB::table('paid_leave_requests')->where('id', $r1)->value('status'));
        $this->assertSame('returned', DB::table('paid_leave_requests')->where('id', $r2)->value('status'));
        $this->assertEqualsWithDelta(9.0, (float) DB::table('paid_leave_grants')->where('id', $grantId)->value('remaining_days'), 0.000001);
    }

    public function test_special_leave_is_rebuilt_into_the_account_stream_and_replays_to_the_same_state(): void
    {
        $user = User::factory()->create();
        $approver = User::factory()->create();
        $type = SpecialLeaveType::query()->create(['name' => '誕生日休暇', 'is_active' => true]);
        [$grantId, $sr1, $sr2] = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];

        $this->insertLegacyEvent($grantId, 'special_leave.granted', ['userId' => $user->id, 'specialLeaveTypeId' => $type->id, 'grantedOn' => '2026-04-01', 'expiresOn' => null, 'grantedDays' => 5.0, 'grantReason' => null]);
        $this->insertLegacyEvent($sr1, 'special_leave.requested', $this->specialRequested($user->id, $approver->id, $type->id, '2026-09-10'));
        $this->insertLegacyEvent($sr1, 'special_leave.request_approved', ['approvedByUserId' => $approver->id]);
        $this->insertLegacyEvent($grantId, 'special_leave.used', $this->usedPayload($user->id, 'specialLeaveRequestId', $sr1, '2026-09-10', 1.0));
        $this->insertLegacyEvent($sr2, 'special_leave.requested', $this->specialRequested($user->id, $approver->id, $type->id, '2026-09-11'));
        $this->insertLegacyEvent($sr2, 'special_leave.request_cancelled', ['cancelledByUserId' => $user->id]);

        $this->rebuildAndSwap();

        $this->assertSame(0, DB::table('stored_events')->whereIn('event_class', ['special_leave.granted', 'special_leave.used'])->count());
        $this->assertSame(1, DB::table('stored_events')->where('event_class', 'special_leave_account.usage_confirmed')->count());
        $this->assertSame(1, DB::table('stored_events')->where('event_class', 'special_leave_account.usage_cancelled')->count());

        $this->replayReadModels();

        $this->assertSame('approved', DB::table('special_leave_requests')->where('id', $sr1)->value('status'));
        $this->assertSame('cancelled', DB::table('special_leave_requests')->where('id', $sr2)->value('status'));
        $this->assertEqualsWithDelta(4.0, (float) DB::table('special_leave_grants')->where('id', $grantId)->value('remaining_days'), 0.000001);
    }

    public function test_compensatory_leave_is_rebuilt_into_the_account_stream_and_replays_to_the_same_state(): void
    {
        $user = User::factory()->create();
        $approver = User::factory()->create();
        [$grantId, $cr] = [(string) Str::uuid(), (string) Str::uuid()];

        $this->insertLegacyEvent($grantId, 'compensatory_leave.manually_granted', ['userId' => $user->id, 'workDate' => '2026-08-01', 'grantedDays' => 1.0, 'grantedMinutes' => null, 'expiresOn' => null, 'grantReason' => '手動']);
        $this->insertLegacyEvent($cr, 'compensatory_leave.requested', [
            'userId' => $user->id, 'targetDate' => '2026-09-10', 'leaveType' => 'full', 'hours' => null, 'requestedDays' => 1.0, 'requestedMinutes' => null,
            'approverUserId' => $approver->id, 'reason' => null, 'requestGroupId' => null,
        ]);
        $this->insertLegacyEvent($cr, 'compensatory_leave.request_approved', ['approvedByUserId' => $approver->id, 'userId' => null]);
        $this->insertLegacyEvent($grantId, 'compensatory_leave.used', $this->usedPayload($user->id, 'compensatoryLeaveRequestId', $cr, '2026-09-10', 1.0));

        $this->rebuildAndSwap();

        $approved = DB::table('stored_events')->where('event_class', 'compensatory_leave.request_approved')->first();
        $this->assertSame($user->id, json_decode($approved->event_properties, true)['userId']);
        $this->assertSame(1, DB::table('stored_events')->where('event_class', 'compensatory_leave_account.usage_confirmed')->count());

        $this->replayReadModels();

        $this->assertSame('approved', DB::table('compensatory_leave_requests')->where('id', $cr)->value('status'));
        $this->assertEqualsWithDelta(0.0, (float) DB::table('compensatory_leave_grants')->where('id', $grantId)->value('remaining_days'), 0.000001);
    }

    public function test_attendance_days_get_created_and_deleted_and_lose_the_leave_work_type_and_calculations_are_filled(): void
    {
        $user = User::factory()->create();
        $approver = User::factory()->create();
        $type = SpecialLeaveType::query()->create(['name' => '誕生日休暇', 'is_active' => true]);
        [$kept, $released, $skeptKept, $skeptReleased] = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];

        // 旧コードが直接作った勤怠日(ReadModelにしか無い。workTypeは休暇値、全休なのにclocked_out)。
        foreach ([[$kept, '2026-09-10'], [$released, '2026-09-11']] as [$dayId, $date]) {
            DB::table('attendance_days')->insert([
                'id' => $dayId, 'user_id' => $user->id, 'work_date' => $date, 'status' => 'clocked_out', 'source' => 'manual',
                'utc_offset_minutes' => 540, 'work_type' => 'special_leave_full', 'created_at' => self::AT, 'updated_at' => self::AT,
            ]);
            $this->insertLegacyEvent($dayId, 'attendance_day.calculated', ['calculation' => $this->calculation(), 'userId' => null, 'workDate' => null]);
        }
        $this->insertLegacyEvent($skeptKept, 'special_leave.requested', $this->specialRequested($user->id, $approver->id, $type->id, '2026-09-10'));
        $this->insertLegacyEvent($skeptKept, 'special_leave.request_approved', ['approvedByUserId' => $approver->id]);
        $this->insertLegacyEvent($skeptReleased, 'special_leave.requested', $this->specialRequested($user->id, $approver->id, $type->id, '2026-09-11'));
        $this->insertLegacyEvent($skeptReleased, 'special_leave.request_approved', ['approvedByUserId' => $approver->id]);
        $this->insertLegacyEvent($skeptReleased, 'special_leave.request_cancelled', ['cancelledByUserId' => $user->id]);

        $this->artisan('leave:rebuild-event-store', ['--apply' => true])->assertExitCode(0);
        $this->artisan('leave:rebuild-event-store', ['--swap' => true])->assertExitCode(0);

        $createdFor = fn (string $dayId) => json_decode((string) DB::table('stored_events')->where('aggregate_uuid', $dayId)->where('event_class', 'attendance_day.created')->value('event_properties'), true);
        $created = $createdFor($kept);
        $this->assertSame('leave', $created['source']);
        $this->assertSame('not_started', $created['status']);
        $this->assertNull($created['workType']);
        $this->assertSame('既存データの引き継ぎ', $created['reason']);
        $calculated = json_decode((string) DB::table('stored_events')->where('aggregate_uuid', $kept)->where('event_class', 'attendance_day.calculated')->value('event_properties'), true);
        $this->assertSame($user->id, $calculated['userId']);
        $this->assertSame('2026-09-10', $calculated['workDate']);
        $this->assertSame(1, DB::table('stored_events')->where('aggregate_uuid', $released)->where('event_class', 'attendance_day.deleted')->count());
        $this->assertSame(0, DB::table('stored_events')->where('aggregate_uuid', $kept)->where('event_class', 'attendance_day.deleted')->count());

        $this->replayReadModels();

        $day = DB::table('attendance_days')->where('id', $kept)->first();
        $this->assertNotNull($day);
        $this->assertSame('leave', $day->source);
        $this->assertSame('not_started', $day->status);
        $this->assertNull($day->work_type);
        $this->assertNull(DB::table('attendance_days')->where('id', $released)->first());
    }

    public function test_legacy_paid_leave_schedule_events_are_replaced_from_the_read_model_and_replay_to_the_same_rows(): void
    {
        $user = User::factory()->create();
        $admin = User::factory()->create();
        [$granted, $cancelled, $review, $plain, $assessmentId] = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];
        $grantId = (string) Str::uuid();

        $entry = fn (string $id, array $values) => DB::table('paid_leave_schedule_entries')->insert($values + [
            'id' => $id, 'user_id' => $user->id, 'scheduled_on' => '2026-10-01', 'category' => 'standard', 'candidate_grant_days' => 10.0,
            'status' => 'Scheduled', 'is_manually_overridden' => false, 'created_at' => self::AT, 'updated_at' => self::AT,
        ]);
        $entry($granted, [
            'status' => 'Granted', 'latest_assessment_id' => $assessmentId, 'grant_id' => $grantId, 'category' => 'adjusted', 'candidate_grant_days' => 11.0,
            'is_manually_overridden' => true, 'manual_override_reason' => '調整', 'manual_override_by_user_id' => $admin->id, 'manual_override_at' => '2026-09-03 08:00:00',
        ]);
        $entry($cancelled, ['status' => 'Cancelled', 'cancelled_reason' => '再計算のため']);
        $entry($review, ['status' => 'NeedsReview']);
        $entry($plain, []);
        DB::table('paid_leave_schedule_assessments')->insert([
            'id' => $assessmentId, 'schedule_entry_id' => $granted, 'period_start' => '2025-04-01', 'period_end' => '2026-03-31', 'denominator_days' => 240,
            'attendance_days' => 190, 'excluded_days' => 2, 'attendance_rate' => 79.17, 'policy_version' => 'v1', 'automatic_result' => 'NotEligible',
            'final_result' => 'Eligible', 'override_reason' => '例外承認', 'overridden_by_user_id' => $admin->id, 'created_at' => self::AT, 'updated_at' => self::AT,
        ]);
        $before = $this->scheduleSnapshot();

        // 旧版のイベント(entryId。集約は行id)。plain は旧版のイベントを持つ(entry_createdのみ)。
        foreach ([$granted, $cancelled, $review, $plain] as $id) {
            $this->insertLegacyEvent($id, 'paid_leave_schedule.entry_created', ['entryId' => $id, 'userId' => $user->id, 'scheduledOn' => '2026-10-01']);
        }
        $this->insertLegacyEvent($granted, 'paid_leave_schedule.entry_granted', ['entryId' => $granted, 'grantId' => $grantId, 'operatorUserId' => $admin->id]);
        $this->insertLegacyEvent($cancelled, 'paid_leave_schedule.entry_superseded', ['entryId' => $cancelled, 'reason' => '再計算のため']);

        $this->rebuildAndSwap();

        $this->assertSame(0, DB::table('stored_events')->whereIn('aggregate_uuid', [$granted, $cancelled, $review, $plain])->count());
        $rows = DB::table('stored_events')->where('aggregate_uuid', $user->id)->orderBy('id')->get();
        $this->assertSame(range(1, $rows->count()), $rows->pluck('aggregate_version')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(4, $rows->where('event_class', 'paid_leave_schedule.entry_created')->count());
        $this->assertSame(1, $rows->where('event_class', 'paid_leave_schedule.assessment_overridden')->count());
        $this->assertSame(1, $rows->where('event_class', 'paid_leave_schedule.entry_cancelled')->count());
        foreach ($rows as $row) {
            $meta = json_decode($row->meta_data, true);
            $this->assertSame($user->id, $meta['aggregate-root-uuid']);
            $this->assertSame((int) $row->aggregate_version, $meta['aggregate-root-version']);
            $this->assertSame((int) $row->id, $meta['stored-event-id']);
        }

        DB::table('paid_leave_schedule_assessments')->delete();
        DB::table('paid_leave_schedule_entries')->delete();
        $this->replayReadModels();

        $this->assertEquals($before, $this->scheduleSnapshot());
    }

    /** @return array<string, array<int, array<string, mixed>>> 比較用の付与予定ReadModel(日時列を除く) */
    private function scheduleSnapshot(): array
    {
        $entries = DB::table('paid_leave_schedule_entries')->orderBy('id')->get()->map(fn ($r) => [
            'id' => $r->id, 'user_id' => $r->user_id, 'scheduled_on' => substr((string) $r->scheduled_on, 0, 10), 'category' => $r->category,
            'candidate_grant_days' => (float) $r->candidate_grant_days, 'status' => $r->status, 'latest_assessment_id' => $r->latest_assessment_id,
            'is_manually_overridden' => (bool) $r->is_manually_overridden, 'manual_override_reason' => $r->manual_override_reason,
            'manual_override_by_user_id' => $r->manual_override_by_user_id, 'manual_override_at' => $r->manual_override_at !== null ? substr((string) $r->manual_override_at, 0, 19) : null, 'grant_id' => $r->grant_id, 'cancelled_reason' => $r->cancelled_reason,
        ])->all();
        $assessments = DB::table('paid_leave_schedule_assessments')->orderBy('id')->get()->map(fn ($r) => [
            'id' => $r->id, 'schedule_entry_id' => $r->schedule_entry_id, 'period_start' => substr((string) $r->period_start, 0, 10),
            'period_end' => substr((string) $r->period_end, 0, 10), 'denominator_days' => (int) $r->denominator_days, 'attendance_days' => (int) $r->attendance_days,
            'excluded_days' => (int) $r->excluded_days, 'attendance_rate' => (float) $r->attendance_rate, 'policy_version' => $r->policy_version,
            'automatic_result' => $r->automatic_result, 'final_result' => $r->final_result, 'override_reason' => $r->override_reason,
            'overridden_by_user_id' => $r->overridden_by_user_id,
        ])->all();

        return ['entries' => $entries, 'assessments' => $assessments];
    }

    private function rebuildAndSwap(): void
    {
        $this->artisan('leave:rebuild-event-store')->assertExitCode(0);
        $this->artisan('leave:rebuild-event-store', ['--apply' => true])->assertExitCode(0);
        $this->artisan('leave:rebuild-event-store', ['--swap' => true])->assertExitCode(0);
    }

    /** 休暇・勤怠のReadModelを空にして、全Projectorをイベントから再生成する。 */
    private function replayReadModels(): void
    {
        foreach ([
            'attendance_day_leaves', 'attendance_daily_calculations', 'attendance_weekly_overtime_allocations', 'attendance_breaks', 'attendance_leave_segments',
            'attendance_days', 'paid_leave_usage_allocations', 'paid_leave_usages', 'paid_leave_requests', 'paid_leave_grants',
            'special_leave_usages', 'special_leave_requests', 'special_leave_grants',
            'compensatory_leave_usages', 'compensatory_leave_requests', 'compensatory_leave_grants',
            'leave_request_workflow_links', 'workflow_request_history_entries', 'workflow_requests',
            'paid_leave_schedule_assessments', 'paid_leave_schedule_entries',
        ] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }

        Artisan::call('event-sourcing:replay', ['--force' => true]);
    }

    /** @param  array<string, mixed>  $payload */
    private function insertLegacyEvent(string $aggregate, string $class, array $payload): void
    {
        $this->versions[$aggregate] = ($this->versions[$aggregate] ?? 0) + 1;
        DB::table('stored_events')->insert([
            'id' => $this->nextId++,
            'aggregate_uuid' => $aggregate,
            'aggregate_version' => $this->versions[$aggregate],
            'event_version' => 1,
            'event_class' => $class,
            'event_properties' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'meta_data' => json_encode([
                'aggregate-root-uuid' => $aggregate,
                'aggregate-root-version' => $this->versions[$aggregate],
                'stored-event-id' => $this->nextId - 1,
                'created-at' => self::AT,
            ]),
            'created_at' => self::AT,
        ]);
    }

    /** @return array<string, mixed> */
    private function drafted(string $applicantId, string $approverId, string $subjectId): array
    {
        return [
            'requestTypeId' => null, 'requestTypeCode' => null, 'applicantUserId' => $applicantId, 'title' => '有給申請', 'formData' => [],
            'approverUserId' => $approverId, 'subjectType' => 'paid_leave_request', 'subjectId' => $subjectId,
        ];
    }

    /** @return array<string, mixed> */
    private function designated(string $usageId, string $workflowId, string $requestId, string $usedOn, string $approverId): array
    {
        return [
            'usageId' => $usageId, 'workflowRequestId' => $workflowId, 'attendanceDayId' => null, 'usedOn' => $usedOn, 'usedDays' => 1.0, 'usageType' => 'full',
            'paidLeaveRequestId' => $requestId, 'approverUserId' => $approverId, 'reason' => null, 'requestGroupId' => null, 'hours' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function specialRequested(string $userId, string $approverId, int $typeId, string $date): array
    {
        return [
            'userId' => $userId, 'specialLeaveTypeId' => $typeId, 'targetDate' => $date, 'leaveType' => 'full', 'hours' => null,
            'requestedDays' => 1.0, 'approverUserId' => $approverId, 'reason' => null, 'requestGroupId' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function usedPayload(string $userId, string $requestKey, string $requestId, string $date, float $days): array
    {
        return [
            'userId' => $userId, $requestKey => $requestId, 'attendanceDayId' => (string) Str::uuid(), 'usedOn' => $date,
            'usedDays' => $days, 'usedMinutes' => null, 'usageType' => 'full',
        ];
    }

    /** @return array<string, mixed> */
    private function calculation(): array
    {
        $zero = [
            'planned_work_minutes', 'work_minutes', 'deemed_work_minutes', 'payroll_work_minutes', 'prescribed_work_minutes',
            'statutory_within_overtime_minutes', 'statutory_excess_overtime_minutes', 'late_night_work_minutes', 'legal_holiday_work_minutes',
            'prescribed_holiday_work_minutes', 'late_night_legal_holiday_work_minutes',
        ];

        return array_fill_keys($zero, 0) + ['day_classification' => 'working_day'];
    }
}
