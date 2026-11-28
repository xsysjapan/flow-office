<?php

namespace Tests\Feature\Leave;

use App\Domain\CompensatoryLeave\Commands\RequestCompensatoryLeave;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\PaidLeave\Commands\RequestPaidLeave;
use App\Domain\PaidLeaveAccount\Commands\GrantPaidLeave;
use App\Domain\ShiftSwap\Commands\RequestShiftSwap;
use App\Domain\SpecialLeave\Commands\RequestSpecialLeave;
use App\Domain\Workflow\Commands\DraftWorkflowRequest;
use App\Models\CompanyCalendar;
use App\Models\CompensatoryLeaveRequest;
use App\Models\EmployeeCalendarEntry;
use App\Models\PaidLeaveRequest;
use App\Models\ShiftSwapRequest;
use App\Models\SpecialLeaveRequest;
use App\Models\SpecialLeaveType;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WorkflowRequest;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\Feature\CompensatoryLeave\CompensatoryLeaveTestHelpers;
use Tests\Feature\SpecialLeave\SpecialLeaveTestHelpers;
use Tests\Support\AssertsSingleTopLevelCommand;
use Tests\TestCase;

/**
 * 休暇・振替休日(有給・特別休暇・代休・振替休日)の申請の入口(原則17、変更セット20261128-single-command-entrypoints 論点5)。
 *
 * - 入口は2つ: 承認不要の直接の申請(POST /{kind}/requests。`Request{X}`を1つdispatch)と、承認が必要な申請
 *   (POST /{kind}/requests/workflow。`DraftWorkflowRequest`を1つdispatch)。Controllerは承認の要否で分岐せず、
 *   Handlerが設定と違う入口を422で拒否する。
 * - 承認不要の直接の申請は、申請と承認(承認者なし)を1つのHandler・1トランザクションで記録する。記録するイベントの
 *   並び(申請→承認の連鎖)は、変更前の「Request{X}とApprove{X}を順に2つdispatch」と同じ。
 * - ワークフロー経由は設定にかかわらず今どおり。
 */
class LeaveRequestEntrypointsTest extends TestCase
{
    use AssertsSingleTopLevelCommand;
    use CompensatoryLeaveTestHelpers;
    use RefreshDatabase;
    use SpecialLeaveTestHelpers;

    /**
     * 承認不要の設定で直接の申請をしたときに記録されるイベントの並び。変更前の入口(Request{X}とApprove{X}を2つ順にdispatch)で
     * 同じ準備から記録した並びと一致する(変更前のコードで実測して固定した)。
     */
    private const EXPECTED_DIRECT_REQUEST_EVENTS = [
        'paid' => ['paid_leave_request.requested', 'attendance_day.created', 'attendance_day.calculated', 'paid_leave_account.usage_designated', 'paid_leave_request.approved', 'paid_leave_account.usage_confirmed', 'paid_leave_account.usage_allocated'],
        'special' => ['special_leave.requested', 'attendance_day.created', 'attendance_day.calculated', 'special_leave_account.usage_designated', 'special_leave.request_approved', 'special_leave_account.usage_confirmed'],
        'compensatory' => ['compensatory_leave.requested', 'attendance_day.created', 'attendance_day.calculated', 'compensatory_leave_account.usage_designated', 'compensatory_leave.request_approved', 'compensatory_leave_account.usage_confirmed'],
        'shift_swap' => ['shift_swap.requested', 'shift_swap.request_approved', 'employee_calendar_entry.assigned', 'employee_calendar_entry.assigned'],
    ];

    /** 休暇の種類ごとの設定: API・設定の列・直接のCommand・メッセージの休暇名。 */
    private const KINDS = [
        'paid' => ['path' => 'paid-leave', 'setting' => 'paid_leave_requires_approval', 'command' => RequestPaidLeave::class, 'label' => '有給休暇'],
        'special' => ['path' => 'special-leave', 'setting' => 'special_leave_requires_approval', 'command' => RequestSpecialLeave::class, 'label' => '特別休暇'],
        'compensatory' => ['path' => 'compensatory-leave', 'setting' => 'compensatory_leave_requires_approval', 'command' => RequestCompensatoryLeave::class, 'label' => '代休'],
        'shift_swap' => ['path' => 'shift-swap', 'setting' => 'shift_swap_requires_approval', 'command' => RequestShiftSwap::class, 'label' => '振替休日'],
    ];

    /** @return array<string, array{0: string}> */
    public static function kinds(): array
    {
        return array_combine(array_keys(self::KINDS), array_map(fn ($kind) => [$kind], array_keys(self::KINDS)));
    }

    // ---- 準備 ----

    /**
     * 種類ごとの残数・勤務予定を用意し、申請の入力(承認者を除く)を返す。
     *
     * @return array{0: User, 1: array<string, mixed>}
     */
    private function prepare(string $kind): array
    {
        $employee = User::factory()->create();

        return [$employee, match ($kind) {
            'paid' => $this->preparePaid($employee),
            'special' => $this->prepareSpecial($employee),
            'compensatory' => $this->prepareCompensatory($employee),
            'shift_swap' => $this->prepareShiftSwap($employee),
        }];
    }

    private function workStyle(): WorkStyle
    {
        $calendar = CompanyCalendar::query()->create(['name' => '2026年度-'.Str::random(6), 'week_starts_on' => 1]);
        $calendar->years()->create(['fiscal_year' => 2026, 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31', 'status' => 'published']);

        return WorkStyle::query()->create([
            'code' => 'fixed-'.Str::random(8), 'name' => '固定勤務', 'work_time_system' => WorkStyle::WORK_TIME_SYSTEM_FIXED,
            'prescribed_daily_minutes' => 480, 'prescribed_weekly_minutes' => 2400,
            'default_start_time' => '09:00', 'default_end_time' => '18:00',
            'default_break_minutes' => 60, 'company_calendar_id' => $calendar->id, 'is_shift_based' => false,
        ]);
    }

    /** @return array<string, mixed> */
    private function preparePaid(User $employee): array
    {
        $this->makeCompensatoryWorkingDayShift($employee, $this->workStyle(), '2026-08-10');
        app(CommandBus::class)->dispatch(new GrantPaidLeave($employee->id, '2025-07-01', '2027-06-30', 10.0, null));

        return ['target_date' => '2026-08-10', 'leave_type' => 'full', 'reason' => '私用'];
    }

    /** @return array<string, mixed> */
    private function prepareSpecial(User $employee): array
    {
        $type = SpecialLeaveType::query()->create(['name' => '誕生日休暇', 'is_active' => true]);
        $this->makeCompensatoryWorkingDayShift($employee, $this->workStyle(), '2026-08-10');
        $this->grantSpecialLeave([
            'user_id' => $employee->id, 'special_leave_type_id' => $type->id,
            'granted_on' => '2025-07-01', 'expires_on' => '2027-06-30', 'granted_days' => 10,
        ]);

        return ['special_leave_type_id' => $type->id, 'target_date' => '2026-08-10', 'leave_type' => 'full', 'reason' => '私用'];
    }

    /** @return array<string, mixed> */
    private function prepareCompensatory(User $employee): array
    {
        $this->holidayWorkGrantedAndConfirmed($employee);
        $this->makeCompensatoryWorkingDayShift($employee, WorkStyle::query()->firstOrFail(), '2026-09-10');

        return ['target_date' => '2026-09-10', 'leave_type' => 'full', 'reason' => '代休消化'];
    }

    /** @return array<string, mixed> */
    private function prepareShiftSwap(User $employee): array
    {
        $workStyle = $this->workStyle();
        foreach (['2026-08-10', '2026-08-11', '2026-08-12', '2026-08-13', '2026-08-14'] as $date) {
            EmployeeCalendarEntry::query()->create([
                'user_id' => $employee->id, 'work_date' => $date, 'work_style_id' => $workStyle->id,
                'day_type' => 'weekday', 'is_working_day' => true, 'is_legal_holiday' => false, 'is_company_holiday' => false,
                'planned_start_at' => "{$date} 09:00:00", 'planned_end_at' => "{$date} 15:40:00", 'planned_break_minutes' => 0,
            ]);
        }
        EmployeeCalendarEntry::query()->create([
            'user_id' => $employee->id, 'work_date' => '2026-08-16', 'work_style_id' => $workStyle->id,
            'day_type' => 'legal_holiday', 'is_working_day' => false, 'is_legal_holiday' => true, 'is_company_holiday' => false,
            'planned_start_at' => null, 'planned_end_at' => null, 'planned_break_minutes' => 0,
        ]);

        return ['target_date' => '2026-08-16', 'substitute_date' => '2026-08-12', 'reason' => '振替'];
    }

    private function setApprovalRequired(string $kind, bool $required): void
    {
        SystemSetting::current()->update([self::KINDS[$kind]['setting'] => $required]);
    }

    /** 申請の行(読み取りビュー)の件数。 */
    private function requestCount(string $kind): int
    {
        return match ($kind) {
            'paid' => PaidLeaveRequest::query()->count(),
            'special' => SpecialLeaveRequest::query()->count(),
            'compensatory' => CompensatoryLeaveRequest::query()->count(),
            'shift_swap' => ShiftSwapRequest::query()->count(),
        };
    }

    private function requestStatus(string $kind, string $id): string
    {
        return match ($kind) {
            'paid' => PaidLeaveRequest::query()->findOrFail($id)->status,
            'special' => SpecialLeaveRequest::query()->findOrFail($id)->status,
            'compensatory' => CompensatoryLeaveRequest::query()->findOrFail($id)->status,
            'shift_swap' => ShiftSwapRequest::query()->findOrFail($id)->status,
        };
    }

    private function lastStoredEventId(): int
    {
        return (int) EloquentStoredEvent::query()->max('id');
    }

    /** @return list<string> 指定のIDより後に記録されたイベントの種別(記録の順)。 */
    private function eventClassesAfter(int $storedEventId): array
    {
        return EloquentStoredEvent::query()->where('id', '>', $storedEventId)->orderBy('id')->pluck('event_class')->all();
    }

    // ---- 承認不要の設定: 直接の申請 ----

    #[DataProvider('kinds')]
    public function test_direct_request_without_approval_records_the_request_and_the_approval(string $kind): void
    {
        [$employee, $payload] = $this->prepare($kind);
        $this->setApprovalRequired($kind, false);
        $before = $this->lastStoredEventId();
        $bus = $this->recordCommands();

        $response = $this->actingAs($employee)->postJson('/api/'.self::KINDS[$kind]['path'].'/requests', $payload);

        $response->assertCreated();
        $response->assertJsonPath('status', 'approved');
        $this->assertSingleTopLevelCommand($bus, self::KINDS[$kind]['command']);
        $this->assertSame('approved', $this->requestStatus($kind, $response->json('id')));
        // ワークフローは作らない。
        $this->assertSame(0, WorkflowRequest::query()->where('subject_id', $response->json('id'))->count());

        $this->assertSame(self::EXPECTED_DIRECT_REQUEST_EVENTS[$kind], $this->eventClassesAfter($before), '申請→承認の連鎖のイベントの並びが変更前と同じ');
    }

    #[DataProvider('kinds')]
    public function test_direct_request_without_approval_uses_the_applicant_as_the_approver_when_none_is_given(string $kind): void
    {
        [$employee, $payload] = $this->prepare($kind);
        $this->setApprovalRequired($kind, false);

        $response = $this->actingAs($employee)->postJson('/api/'.self::KINDS[$kind]['path'].'/requests', $payload);

        $response->assertCreated();
        $response->assertJsonPath('approver.id', $employee->id);
    }

    #[DataProvider('kinds')]
    public function test_direct_request_without_approval_that_fails_in_the_chain_leaves_nothing(string $kind): void
    {
        [$employee, $payload] = $this->prepare($kind);
        $this->setApprovalRequired($kind, false);
        if ($kind === 'shift_swap') {
            // 振替先が対象日と同じ日で拒否される。
            $payload['target_date'] = '2026-08-16';
            $payload['substitute_date'] = '2026-08-16';
        } else {
            // 時間休で時間数が無いので、申請の検証(Handler)で拒否される。
            $payload['leave_type'] = 'hourly';
            $payload['hours'] = null;
        }
        $before = $this->lastStoredEventId();

        $this->actingAs($employee)->postJson('/api/'.self::KINDS[$kind]['path'].'/requests', $payload)->assertStatus(422);

        $this->assertSame($before, $this->lastStoredEventId());
        $this->assertSame(0, $this->requestCount($kind));
    }

    // ---- 承認が必要な設定: 直接の申請は拒否 ----

    #[DataProvider('kinds')]
    public function test_direct_request_when_approval_is_required_is_rejected_and_leaves_nothing(string $kind): void
    {
        [$employee, $payload] = $this->prepare($kind);
        $this->setApprovalRequired($kind, true);
        $before = $this->lastStoredEventId();
        $workflowCount = WorkflowRequest::query()->count();
        $bus = $this->recordCommands();

        $response = $this->actingAs($employee)->postJson('/api/'.self::KINDS[$kind]['path'].'/requests', $payload);

        $response->assertStatus(422);
        $this->assertStringContainsString(self::KINDS[$kind]['label'].'は承認が必要です。申請から承認者を指定して申請してください。', $response->json('message'));
        $this->assertSingleTopLevelCommand($bus, self::KINDS[$kind]['command']);
        $this->assertSame($before, $this->lastStoredEventId());
        $this->assertSame(0, $this->requestCount($kind));
        $this->assertSame($workflowCount, WorkflowRequest::query()->count());
    }

    // ---- 承認が必要な設定: ワークフローの入口 ----

    #[DataProvider('kinds')]
    public function test_workflow_entrypoint_when_approval_is_required_creates_the_workflow_and_a_submitted_request(string $kind): void
    {
        [$employee, $payload] = $this->prepare($kind);
        $this->setApprovalRequired($kind, true);
        $approver = User::factory()->create();
        $bus = $this->recordCommands();

        $response = $this->actingAs($employee)->postJson('/api/'.self::KINDS[$kind]['path'].'/requests/workflow', $payload + ['approver_user_id' => $approver->id]);

        $response->assertCreated();
        $response->assertJsonPath('status', 'submitted');
        $this->assertSingleTopLevelCommand($bus, DraftWorkflowRequest::class);
        $workflow = WorkflowRequest::query()->where('subject_id', $response->json('id'))->firstOrFail();
        $this->assertSame('submitted', $workflow->status);
        $this->assertSame($approver->id, $workflow->approver_user_id);
        $this->assertSame('submitted', $this->requestStatus($kind, $response->json('id')));
    }

    #[DataProvider('kinds')]
    public function test_workflow_entrypoint_requires_the_approver(string $kind): void
    {
        [$employee, $payload] = $this->prepare($kind);
        $this->setApprovalRequired($kind, true);

        $this->actingAs($employee)->postJson('/api/'.self::KINDS[$kind]['path'].'/requests/workflow', $payload)
            ->assertStatus(422)->assertJsonValidationErrors('approver_user_id');

        $this->assertSame(0, $this->requestCount($kind));
    }

    // ---- 承認不要の設定: ワークフローの入口は拒否 ----

    #[DataProvider('kinds')]
    public function test_workflow_entrypoint_without_approval_is_rejected_and_leaves_nothing(string $kind): void
    {
        [$employee, $payload] = $this->prepare($kind);
        $this->setApprovalRequired($kind, false);
        $approver = User::factory()->create();
        $before = $this->lastStoredEventId();
        $workflowCount = WorkflowRequest::query()->count();
        $bus = $this->recordCommands();

        $response = $this->actingAs($employee)->postJson('/api/'.self::KINDS[$kind]['path'].'/requests/workflow', $payload + ['approver_user_id' => $approver->id]);

        $response->assertStatus(422);
        $this->assertStringContainsString(self::KINDS[$kind]['label'].'は承認が不要です。直接申請してください。', $response->json('message'));
        $this->assertSingleTopLevelCommand($bus, DraftWorkflowRequest::class);
        $this->assertSame($before, $this->lastStoredEventId());
        $this->assertSame(0, $this->requestCount($kind));
        $this->assertSame($workflowCount, WorkflowRequest::query()->count());
    }

    // ---- ワークフロー経由(Reactor発行)は承認の要否を見ない ----

    #[DataProvider('reactorKinds')]
    public function test_a_request_issued_by_the_workflow_reactor_ignores_the_approval_setting(string $kind): void
    {
        [$employee, $payload] = $this->prepare($kind);
        $this->setApprovalRequired($kind, false);
        $approver = User::factory()->create();
        $requestId = (string) Str::uuid();

        $command = match ($kind) {
            'paid' => new RequestPaidLeave($requestId, $employee->id, $payload['target_date'], $payload['leave_type'], null, $approver->id, null, viaReactor: true, initiatedByUserId: $employee->id),
            'special' => new RequestSpecialLeave($employee->id, $payload['special_leave_type_id'], $payload['target_date'], $payload['leave_type'], null, $approver->id, null, requestId: $requestId, viaReactor: true, initiatedByUserId: $employee->id),
            'compensatory' => new RequestCompensatoryLeave($employee->id, $payload['target_date'], $payload['leave_type'], null, $approver->id, null, requestId: $requestId, viaReactor: true, initiatedByUserId: $employee->id),
        };
        app(CommandBus::class)->dispatch($command);

        $this->assertSame('submitted', $this->requestStatus($kind, $requestId));
    }

    /** @return array<string, array{0: string}> */
    public static function reactorKinds(): array
    {
        return ['paid' => ['paid'], 'special' => ['special'], 'compensatory' => ['compensatory']];
    }
}
