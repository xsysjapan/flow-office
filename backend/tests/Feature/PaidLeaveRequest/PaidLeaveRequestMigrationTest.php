<?php

namespace Tests\Feature\PaidLeaveRequest;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\PaidLeave\Commands\ApprovePaidLeaveRequest;
use App\Domain\PaidLeave\Commands\CancelPaidLeaveRequest;
use App\Domain\PaidLeave\Commands\ResubmitPaidLeaveRequest;
use App\Domain\PaidLeave\Events\PaidLeaveRequestApproved;
use App\Domain\PaidLeave\Events\PaidLeaveRequestReturned;
use App\Domain\PaidLeave\Events\PaidLeaveRequested;
use App\Domain\PaidLeave\Events\PaidLeaveRequestShared;
use App\Domain\LeaveRequestLink\Projectors\LeaveRequestWorkflowLinkProjector;
use App\Domain\PaidLeaveAccount\Aggregates\PaidLeaveAccountAggregate;
use App\Domain\PaidLeaveAccount\Commands\ConfirmPaidLeaveUsage;
use App\Domain\PaidLeaveAccount\Commands\DesignatePaidLeaveUsage;
use App\Domain\PaidLeaveAccount\Commands\GrantPaidLeave;
use App\Domain\PaidLeaveRequest\Aggregates\PaidLeaveRequestAggregate;
use App\Domain\PaidLeaveRequest\Projectors\PaidLeaveRequestProjector;
use App\Domain\Workflow\Commands\DraftWorkflowRequest;
use App\Domain\Workflow\Commands\RejectWorkflowRequest;
use App\Domain\Workflow\Commands\SubmitWorkflowRequest;
use App\Models\AttendanceDayLeave;
use App\Models\CompanyCalendar;
use App\Models\EmployeeCalendarEntry;
use App\Models\PaidLeaveGrant;
use App\Models\PaidLeaveRequest;
use App\Models\RequestType;
use App\Models\User;
use App\Models\WorkflowRequest;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

/**
 * 本変更前の有給申請の引き継ぎ(運用コマンド paid-leave:migrate-requests・`paid_leave_request.migrated`)の
 * シナリオテスト(仕様確定事項I。UI非依存、SQLite+Eloquent)。
 *
 * 引き継ぎ前の申請は、旧系統のイベントを `stored_events` に記録して作る(ReadModelの直接INSERTはしない):
 * - 旧系統(cutover前): `paid_leave.requested` / `paid_leave.request_returned` / `paid_leave.request_approved`
 *   (消化記録なし)。
 * - cutover後の系統: 口座の `paid_leave_account.usage_designated` / `usage_confirmed`(Commandで記録)。
 *   申請IDごとの状態は有給口座の消化記録から作られる。
 *
 * 旧系統のイベントは `stored_events` に記録したうえで、同じイベントを休暇申請文脈のProjectorへ直接適用する
 * (tests/Feature/Attendance/AttendanceDayLeaveProjectorTest.php と同じ方式)。
 */
class PaidLeaveRequestMigrationTest extends TestCase
{
    use RefreshDatabase;

    /** 旧系統(cutover前)の申請の対象日。 */
    private const LEGACY_DATE = '2026-08-10';

    /** cutover後の系統の申請の対象日。 */
    private const DATE = '2026-08-11';

    private User $employee;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = User::factory()->create();
        $this->approver = User::factory()->create();
        $this->createWorkingDays($this->employee, [self::LEGACY_DATE, self::DATE, '2026-08-12']);
        $this->grant($this->employee, 10.0);
    }

    // ---- 試し実行・引き継ぎ・再実行 ----

    public function test_dry_run_lists_the_targets_and_changes_nothing(): void
    {
        [$approvedLegacy, $returnedLegacy, $submittedAccount, $approvedAccount] = $this->fixtures();
        $before = $this->storedEventCount();

        $this->assertSame(0, Artisan::call('paid-leave:migrate-requests'));

        $this->assertSame($before, $this->storedEventCount(), 'dry-run must not record any event');
        $this->assertSame(0, $this->migratedEventCount());
        foreach ([$approvedLegacy, $returnedLegacy, $submittedAccount, $approvedAccount] as $requestId) {
            $this->assertSame('none', PaidLeaveRequestAggregate::retrieve($requestId)->status());
        }
    }

    public function test_apply_hands_over_every_status_once_and_reruns_are_no_ops(): void
    {
        [$approvedLegacy, $returnedLegacy, $submittedAccount, $approvedAccount] = $this->fixtures();
        $before = $this->storedEventCount();

        $this->assertSame(0, Artisan::call('paid-leave:migrate-requests', ['--apply' => true]));

        // 旧系統(cutover前)の承認済みは消化記録なしで引き継ぐ。差戻しは差戻しのまま、申請中は申請中のまま引き継ぐ。
        $this->assertSame('approved', PaidLeaveRequestAggregate::retrieve($approvedLegacy)->status());
        $this->assertSame('returned', PaidLeaveRequestAggregate::retrieve($returnedLegacy)->status());
        $this->assertSame('submitted', PaidLeaveRequestAggregate::retrieve($submittedAccount)->status());
        $this->assertSame('approved', PaidLeaveRequestAggregate::retrieve($approvedAccount)->status());
        $this->assertTrue(PaidLeaveAccountAggregate::retrieve($this->employee->id)->hasActiveUsageForRequest($submittedAccount));
        $this->assertTrue(PaidLeaveAccountAggregate::retrieve($this->employee->id)->hasActiveUsageForRequest($approvedAccount));

        // 記録されるのは引き継ぎイベントだけ(他文脈のReactorは paid_leave_request.migrated に反応しない)。
        $this->assertSame(4, $this->migratedEventCount());
        $this->assertSame($before + 4, $this->storedEventCount());

        // 再実行しても二重に記録しない。
        $this->assertSame(0, Artisan::call('paid-leave:migrate-requests', ['--apply' => true]));
        $this->assertSame(4, $this->migratedEventCount());
        $this->assertSame($before + 4, $this->storedEventCount());
    }

    public function test_the_readmodel_rows_take_over_the_migrated_state(): void
    {
        [$approvedLegacy, , , $approvedAccount] = $this->fixtures();

        Artisan::call('paid-leave:migrate-requests', ['--apply' => true]);

        $this->assertSame(['approved', PaidLeaveRequest::SOURCE_PAID_REQUEST], $this->rowState($approvedLegacy));
        $this->assertSame(['approved', PaidLeaveRequest::SOURCE_PAID_REQUEST], $this->rowState($approvedAccount));
        // 休暇ビュー(勤怠)も引き継いだ申請の状態を承認済みとして持つ。
        $this->assertSame(
            'approved',
            AttendanceDayLeave::query()->where('leave_kind', AttendanceDayLeave::KIND_PAID)
                ->where('leave_request_id', $approvedLegacy)->value('request_status'),
        );
    }

    // ---- 引き継ぎ後の操作 ----

    public function test_cutover_before_approved_request_cannot_be_cancelled(): void
    {
        [$approvedLegacy] = $this->fixtures();
        Artisan::call('paid-leave:migrate-requests', ['--apply' => true]);

        try {
            app(CommandBus::class)->dispatch(new CancelPaidLeaveRequest(
                paidLeaveRequestId: $approvedLegacy,
                cancelledByUserId: $this->employee->id,
            ));
            $this->fail('移行前の承認済み申請の取消は拒否されること');
        } catch (DomainRuleException $e) {
            $this->assertStringContainsString('移行前の申請のため取消できません', $e->getMessage());
        }

        $this->assertSame('approved', PaidLeaveRequestAggregate::retrieve($approvedLegacy)->status());
        $this->assertSame(['approved', PaidLeaveRequest::SOURCE_PAID_REQUEST], $this->rowState($approvedLegacy));
    }

    public function test_cutover_after_approved_request_cancel_restores_the_grant(): void
    {
        [, , , $approvedAccount] = $this->fixtures();
        Artisan::call('paid-leave:migrate-requests', ['--apply' => true]);
        $this->assertRemainingDays(9.0);

        app(CommandBus::class)->dispatch(new CancelPaidLeaveRequest(
            paidLeaveRequestId: $approvedAccount,
            cancelledByUserId: $this->employee->id,
        ));

        $this->assertSame('cancelled', PaidLeaveRequestAggregate::retrieve($approvedAccount)->status());
        $this->assertSame('cancelled', PaidLeaveAccountAggregate::retrieve($this->employee->id)->usageStatus(
            PaidLeaveAccountAggregate::retrieve($this->employee->id)->usageIdForRequest($approvedAccount),
        ));
        $this->assertRemainingDays(10.0);
    }

    public function test_migrated_submitted_request_can_be_approved_and_consumes_the_grant(): void
    {
        // fixtures() で確定済みの1日(承認済みのcutover後の申請)を差し引いた残数が基準。
        [, , $submittedAccount] = $this->fixtures();
        Artisan::call('paid-leave:migrate-requests', ['--apply' => true]);
        $this->assertRemainingDays(9.0);

        app(CommandBus::class)->dispatch(new ApprovePaidLeaveRequest(
            paidLeaveRequestId: $submittedAccount,
            approvedByUserId: $this->approver->id,
        ));

        $this->assertSame('approved', PaidLeaveRequestAggregate::retrieve($submittedAccount)->status());
        $this->assertSame(['approved', PaidLeaveRequest::SOURCE_PAID_REQUEST], $this->rowState($submittedAccount));
        $this->assertRemainingDays(8.0);
    }

    public function test_migrated_returned_request_can_be_resubmitted_and_approved_with_one_usage(): void
    {
        // fixtures() で確定済みの1日を差し引いた残数が基準。差戻し中は消化記録が取り消されているため残数は変わらない。
        [, $returnedLegacy] = $this->fixtures();
        Artisan::call('paid-leave:migrate-requests', ['--apply' => true]);
        $this->assertRemainingDays(9.0);

        app(CommandBus::class)->dispatch(new ResubmitPaidLeaveRequest(
            paidLeaveRequestId: $returnedLegacy,
            resubmittedByUserId: $this->employee->id,
        ));

        // 再提出で申請中に戻り、新しい消化記録は1件(差戻しで取り消されている旧い分と二重に計上しない)。
        $this->assertSame('submitted', PaidLeaveRequestAggregate::retrieve($returnedLegacy)->status());
        $this->assertTrue(PaidLeaveAccountAggregate::retrieve($this->employee->id)->hasActiveUsageForRequest($returnedLegacy));
        $this->assertRemainingDays(9.0);

        app(CommandBus::class)->dispatch(new ApprovePaidLeaveRequest(
            paidLeaveRequestId: $returnedLegacy,
            approvedByUserId: $this->approver->id,
        ));

        $this->assertSame('approved', PaidLeaveRequestAggregate::retrieve($returnedLegacy)->status());
        $this->assertRemainingDays(8.0);
    }

    public function test_rebuilding_the_projections_after_migration_reproduces_the_same_state(): void
    {
        [$approvedLegacy, $returnedLegacy, $submittedAccount, $approvedAccount] = $this->fixtures();
        Artisan::call('paid-leave:migrate-requests', ['--apply' => true]);

        $before = [];
        foreach ([$approvedLegacy, $returnedLegacy, $submittedAccount, $approvedAccount] as $requestId) {
            $before[$requestId] = $this->rowState($requestId);
        }

        // 休暇申請文脈の派生データ(申請・対応表)を空にして、イベントから再生成する。
        DB::table('paid_leave_requests')->delete();
        DB::table('paid_leave_request_usage_links')->delete();
        DB::table('leave_request_workflow_links')->delete();
        Artisan::call('event-sourcing:replay', ['--force' => true]);

        foreach ($before as $requestId => $state) {
            $this->assertSame($state, $this->rowState($requestId), "rebuilt state of {$requestId}");
        }
        $this->assertSame(4, $this->migratedEventCount());
    }

    public function test_a_submitted_request_whose_workflow_was_rejected_is_handed_over_as_cancelled_and_its_usage_is_cancelled(): void
    {
        $requestId = $this->uuid();
        $this->designate($requestId);
        $workflowRequestId = $this->submittedWorkflowRequest();

        // 旧系統の申請と申請済みワークフローの対応(旧`paid_leave.request_shared`)を記録する。
        $this->storeLegacy($requestId, 'paid_leave.request_shared', ['workflowRequestId' => $workflowRequestId]);
        app(LeaveRequestWorkflowLinkProjector::class)->onPaidLeaveRequestShared(
            (new PaidLeaveRequestShared(workflowRequestId: $workflowRequestId))->setAggregateRootUuid($requestId),
        );

        app(CommandBus::class)->dispatch(new RejectWorkflowRequest(
            workflowRequestId: $workflowRequestId,
            rejectedByUserId: $this->approver->id,
            reason: '要件を満たしていません',
        ));
        $this->assertRemainingDays(10.0);

        $this->assertSame(0, Artisan::call('paid-leave:migrate-requests', ['--apply' => true]));

        // 申請は取消として引き継ぎ、未確定だった消化記録も取り消される(残数は変わらない)。
        $this->assertSame('cancelled', PaidLeaveRequestAggregate::retrieve($requestId)->status());
        $account = PaidLeaveAccountAggregate::retrieve($this->employee->id);
        $this->assertSame('cancelled', $account->usageStatus($account->usageIdForRequest($requestId)));
        $this->assertSame(['cancelled', PaidLeaveRequest::SOURCE_PAID_REQUEST], $this->rowState($requestId));
        $this->assertSame('rejected', WorkflowRequest::query()->findOrFail($workflowRequestId)->status);
        $this->assertRemainingDays(10.0);

        // 再実行しても二重に取り消さない(引き継ぎ済みの申請は対象外)。
        $before = $this->storedEventCount();
        $this->assertSame(0, Artisan::call('paid-leave:migrate-requests', ['--apply' => true]));
        $this->assertSame($before, $this->storedEventCount());
    }

    // ---- 旧系統・cutover後の系統の申請を作る ----

    /**
     * 引き継ぎ前の申請を4件作る。
     *
     * @return array{0: string, 1: string, 2: string, 3: string} 旧・承認済み / 旧・差戻し / cutover後・申請中 / cutover後・承認済み
     */
    private function fixtures(): array
    {
        // 旧系統(cutover前): 承認済み(消化記録なし)。
        $approvedLegacy = $this->uuid();
        $this->legacyRequested($approvedLegacy, self::LEGACY_DATE);
        $this->legacyApproved($approvedLegacy);

        // 旧系統(cutover前): 差戻し(消化記録は取消済みのため無し)。
        $returnedLegacy = $this->uuid();
        $this->legacyRequested($returnedLegacy, self::LEGACY_DATE);
        $this->legacyReturned($returnedLegacy);

        // cutover後の系統: 申請中(未確定の消化記録あり)。
        $submittedAccount = $this->uuid();
        $this->designate($submittedAccount);

        // cutover後の系統: 承認済み(確定済みの消化記録あり)。
        $approvedAccount = $this->uuid();
        $this->designate($approvedAccount);
        app(CommandBus::class)->dispatch(new ConfirmPaidLeaveUsage(
            userId: $this->employee->id,
            usageId: null,
            confirmedByUserId: $this->approver->id,
            paidLeaveRequestId: $approvedAccount,
        ));

        return [$approvedLegacy, $returnedLegacy, $submittedAccount, $approvedAccount];
    }

    private function legacyRequested(string $requestId, string $date): void
    {
        $event = new PaidLeaveRequested(
            userId: $this->employee->id,
            targetDate: $date,
            leaveType: 'full',
            hours: null,
            requestedDays: 1.0,
            approverUserId: $this->approver->id,
            reason: '私用',
            requestGroupId: null,
        );
        $this->storeLegacy($requestId, 'paid_leave.requested', [
            'userId' => $this->employee->id,
            'targetDate' => $date,
            'leaveType' => 'full',
            'hours' => null,
            'requestedDays' => 1.0,
            'approverUserId' => $this->approver->id,
            'reason' => '私用',
            'requestGroupId' => null,
        ]);

        app(PaidLeaveRequestProjector::class)->onPaidLeaveRequested($event->setAggregateRootUuid($requestId));
    }

    private function legacyApproved(string $requestId): void
    {
        $this->storeLegacy($requestId, 'paid_leave.request_approved', ['approvedByUserId' => $this->approver->id]);

        app(PaidLeaveRequestProjector::class)->onPaidLeaveRequestApproved(
            (new PaidLeaveRequestApproved(approvedByUserId: $this->approver->id))->setAggregateRootUuid($requestId),
        );
    }

    private function legacyReturned(string $requestId): void
    {
        $this->storeLegacy($requestId, 'paid_leave.request_returned', [
            'returnedByUserId' => $this->approver->id,
            'comment' => '日程を確認してください',
        ]);

        app(PaidLeaveRequestProjector::class)->onPaidLeaveRequestReturned(
            (new PaidLeaveRequestReturned(returnedByUserId: $this->approver->id, comment: '日程を確認してください'))
                ->setAggregateRootUuid($requestId),
        );
    }

    /** cutover後の系統の消化記録(申請IDを持つ)を口座へ記録する。 */
    private function designate(string $requestId): void
    {
        app(CommandBus::class)->dispatch(new DesignatePaidLeaveUsage(
            userId: $this->employee->id,
            workflowRequestId: null,
            attendanceDayId: null,
            usedOn: self::DATE,
            usedDays: 1.0,
            usageType: 'full',
            paidLeaveRequestId: $requestId,
            approverUserId: $this->approver->id,
            reason: '私用',
            requestGroupId: null,
            hours: null,
        ));
    }

    /** 旧イベントを `stored_events` に記録する(集約ID=申請ID、camelCaseのプロパティ)。 */
    private function storeLegacy(string $requestId, string $eventClass, array $properties): void
    {
        $version = (int) EloquentStoredEvent::query()->where('aggregate_uuid', $requestId)->max('aggregate_version') + 1;

        EloquentStoredEvent::create([
            'aggregate_uuid' => $requestId,
            'aggregate_version' => $version,
            'event_version' => 1,
            'event_class' => $eventClass,
            'event_properties' => $properties,
            'meta_data' => [],
            'created_at' => now(),
        ]);
    }

    // ---- ヘルパー ----

    private function uuid(): string
    {
        return (string) Str::uuid();
    }

    /** 業務と紐づかない申請済みワークフローを作る(却下は業務紐づき以外のワークフローに限られるため)。 */
    private function submittedWorkflowRequest(): string
    {
        $requestType = RequestType::query()->create([
            'code' => 'general_request',
            'name' => '一般申請',
            'form_schema' => [],
            'requires_backoffice_task' => false,
            'is_active' => true,
        ]);

        $draft = app(CommandBus::class)->dispatch(new DraftWorkflowRequest(
            requestTypeCode: $requestType->code,
            applicantUserId: $this->employee->id,
            title: '有給申請',
            formData: [],
            approverUserId: $this->approver->id,
        ));

        app(CommandBus::class)->dispatch(new SubmitWorkflowRequest(
            workflowRequestId: $draft->id,
            submittedByUserId: $this->employee->id,
            approverUserId: $this->approver->id,
        ));

        return (string) $draft->id;
    }

    private function createWorkingDays(User $user, array $dates): void
    {
        $calendar = CompanyCalendar::query()->create(['name' => '2026年度', 'week_starts_on' => 1]);
        $calendar->years()->create(['fiscal_year' => 2026, 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31', 'status' => 'published']);
        $workStyle = WorkStyle::query()->create([
            'code' => 'standard-'.$user->id, 'name' => '通常勤務', 'work_time_system' => 'fixed',
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_start_time' => '09:00', 'default_end_time' => '18:00',
            'default_break_minutes' => 60, 'company_calendar_id' => $calendar->id, 'is_shift_based' => false,
        ]);

        foreach ($dates as $date) {
            EmployeeCalendarEntry::query()->create([
                'user_id' => $user->id, 'work_date' => $date, 'work_style_id' => $workStyle->id,
                'day_type' => 'weekday', 'is_working_day' => true, 'is_legal_holiday' => false, 'is_company_holiday' => false,
                'planned_start_at' => "{$date} 09:00:00", 'planned_end_at' => "{$date} 18:00:00",
                'planned_break_minutes' => 60,
            ]);
        }
    }

    private function grant(User $user, float $days): void
    {
        app(CommandBus::class)->dispatch(new GrantPaidLeave($user->id, '2025-07-01', '2027-06-30', $days, null));
    }

    private function storedEventCount(): int
    {
        return EloquentStoredEvent::query()->count();
    }

    private function migratedEventCount(): int
    {
        return EloquentStoredEvent::query()->where('event_class', 'paid_leave_request.migrated')->count();
    }

    /** @return array{0: string, 1: string} 状態・入力系統 */
    private function rowState(string $requestId): array
    {
        $row = PaidLeaveRequest::query()->findOrFail($requestId);

        return [$row->status, $row->input_source];
    }

    private function assertRemainingDays(float $expected): void
    {
        $this->assertEqualsWithDelta(
            $expected,
            (float) PaidLeaveGrant::query()->where('user_id', $this->employee->id)->sum('remaining_days'),
            0.0001,
        );
    }
}
