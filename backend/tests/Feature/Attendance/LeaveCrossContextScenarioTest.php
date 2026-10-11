<?php

namespace Tests\Feature\Attendance;

use App\Domain\CompensatoryLeaveAccount\Reactors\ConfirmCompensatoryLeaveGrantsOnAttendanceMonthSubmittedReactor;
use App\Domain\CompensatoryLeaveAccount\Reactors\SyncCompensatoryLeaveAccountGrantOnAttendanceDayCalculatedReactor;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\PaidLeaveAccount\Commands\GrantPaidLeave;
use App\Models\AttendanceDailyCalculation;
use App\Models\AttendanceDay;
use App\Models\AttendanceDayLeave;
use App\Models\AttendanceSpecialLeaveShortage;
use App\Models\CompensatoryLeaveGrant;
use App\Models\CompensatoryLeaveUsage;
use App\Models\Notification;
use App\Models\PaidLeaveRequest;
use App\Models\PaidLeaveUsage;
use App\Models\SpecialLeaveGrant;
use App\Models\SpecialLeaveType;
use App\Models\SpecialLeaveUsage;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WorkflowRequest;
use App\Models\WorkStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\EventSourcing\Projectionist;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\Feature\CompensatoryLeave\CompensatoryLeaveTestHelpers;
use Tests\Feature\SpecialLeave\SpecialLeaveTestHelpers;
use Tests\TestCase;

/**
 * 休暇(有給・特別休暇・代休)を横断するシナリオテスト(UI非依存。API操作から始まる連鎖を通し、各文脈の状態を確認する。
 * domain-testスキル。WP11の補完)。
 *
 * - 午前有給+午後代休(日次計算は全休と同じ所定・休暇日数は種類ごとに0.5)
 * - 有給の締め済み(月次提出済み)の日に対する全遷移の拒否と状態不変
 * - 特別休暇・代休の承認不要の申請(即承認・消化の確定・勤怠)
 * - 同じイベントを購読するReactorを個別に・逆順/順に再実行しても結果が同じこと(冪等・実行順に依存しない)
 * - 休暇の申請・承認・差戻し・取消で通知が重複しないこと
 * - 勤怠側の特別休暇の残数不足ビュー(承認で作られ、取消で消え、再生成しても同じ)
 */
class LeaveCrossContextScenarioTest extends TestCase
{
    use CompensatoryLeaveTestHelpers;
    use RefreshDatabase;
    use SpecialLeaveTestHelpers;

    /** 休暇の種類ごとの設定(APIのパスと通知の件名)。 */
    private const KINDS = [
        'paid' => ['path' => 'paid-leave', 'label' => '有給休暇申請'],
        'special' => ['path' => 'special-leave', 'label' => '特別休暇申請'],
        'compensatory' => ['path' => 'compensatory-leave', 'label' => '代休申請'],
    ];

    private ?SpecialLeaveType $specialType = null;

    // ---- 準備 ----

    /**
     * 申請者・承認者と、3種類の休暇の残数(有給10日・特別休暇10日・代休1日(休日出勤の確定済み))を用意する。
     * 勤務予定は makeCompensatoryWorkingDayShift で日ごとに作る。
     *
     * @return array{0: User, 1: User}
     */
    private function peopleWithBalances(): array
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();

        $this->holidayWorkGrantedAndConfirmed($employee);

        app(CommandBus::class)->dispatch(new GrantPaidLeave($employee->id, '2025-07-01', '2027-06-30', 10.0, null));

        $this->specialType = SpecialLeaveType::query()->create(['name' => '誕生日休暇', 'is_active' => true]);
        $this->grantSpecialLeave([
            'user_id' => $employee->id, 'special_leave_type_id' => $this->specialType->id,
            'granted_on' => '2025-07-01', 'expires_on' => '2027-06-30', 'granted_days' => 10,
        ]);

        return [$employee, $approver];
    }

    private function workDay(User $employee, string $date): void
    {
        $this->makeCompensatoryWorkingDayShift($employee, WorkStyle::query()->firstOrFail(), $date);
    }

    /** 休暇を申請して申請IDを返す(勤務予定は日ごとに作る)。承認者がnullなら承認不要設定の申請。 */
    private function requestLeaveOf(string $kind, User $employee, ?User $approver, string $date, string $unit = 'full'): string
    {
        $this->workDay($employee, $date);

        $payload = ['target_date' => $date, 'leave_type' => $unit];
        if ($approver !== null) {
            $payload['approver_user_id'] = $approver->id;
        }
        if ($kind === 'special') {
            $payload['special_leave_type_id'] = $this->specialType->id;
        }

        return $this->actingAs($employee)->postJson('/api/'.self::KINDS[$kind]['path'].'/requests', $payload)
            ->assertCreated()->json('id');
    }

    private function transition(string $kind, string $action, User $actor, string $requestId, array $payload = []): void
    {
        $this->actingAs($actor)->postJson('/api/'.self::KINDS[$kind]['path']."/requests/{$requestId}/{$action}", $payload)->assertOk();
    }

    private function dayOn(User $user, string $date): ?AttendanceDay
    {
        return AttendanceDay::query()->where('user_id', $user->id)->whereDate('work_date', $date)->first();
    }

    private function calcOn(User $user, string $date): ?AttendanceDailyCalculation
    {
        $day = $this->dayOn($user, $date);

        return $day === null ? null : AttendanceDailyCalculation::query()->where('attendance_day_id', $day->id)->first();
    }

    private function workflowIdOf(string $requestId): string
    {
        return WorkflowRequest::query()->where('subject_id', $requestId)->value('id');
    }

    /**
     * どの文脈の状態も変わっていないことを確かめるための状態の要約(イベント件数・ワークフロー・休暇ビュー・勤怠・
     * 消化記録・通知)。
     *
     * @return array<string, mixed>
     */
    private function state(): array
    {
        return [
            'stored_events' => DB::table('stored_events')->count(),
            'workflows' => WorkflowRequest::query()->orderBy('id')->pluck('status', 'id')->all(),
            'paid_requests' => PaidLeaveRequest::query()->orderBy('id')->pluck('status', 'id')->all(),
            'leaves' => AttendanceDayLeave::query()->orderBy('leave_kind')->orderBy('leave_request_id')
                ->get(['leave_kind', 'leave_request_id', 'request_status', 'unit', 'minutes'])->toArray(),
            'days' => AttendanceDay::query()->orderBy('id')->get(['id', 'source', 'actual_start_at'])->toArray(),
            'calculations' => AttendanceDailyCalculation::query()->orderBy('attendance_day_id')
                ->get(['attendance_day_id', 'paid_leave_days', 'special_leave_days', 'prescribed_work_minutes', 'work_minutes'])->toArray(),
            'paid_usages' => PaidLeaveUsage::query()->count(),
            'special_usages' => SpecialLeaveUsage::query()->orderBy('usage_id')->get(['usage_id', 'is_confirmed', 'unallocated_days'])->toArray(),
            'compensatory_usages' => CompensatoryLeaveUsage::query()->count(),
            'compensatory_grants' => CompensatoryLeaveGrant::query()->orderBy('id')->get(['id', 'status', 'remaining_days'])->toArray(),
            'special_grants' => SpecialLeaveGrant::query()->orderBy('id')->get(['id', 'remaining_days'])->toArray(),
            'notifications' => Notification::query()->orderBy('id')->pluck('title')->sort()->values()->all(),
        ];
    }

    private function notificationCount(User $recipient, string $title): int
    {
        return Notification::query()->where('recipient_user_id', $recipient->id)->where('title', $title)->count();
    }

    /**
     * 指定の種別(別名)の最新のイベントを、そのイベントを購読するReactorだけに(Projectorは除く)指定の順で渡して再実行する。
     * 同じイベントを2回処理しても、実行順を入れ替えても、結果が変わらないことの確認に使う。
     * $onlyを指定すると、そのクラスのReactorだけを対象にする。
     *
     * @param  list<class-string>|null  $only
     */
    private function reprocessReactors(string $eventAlias, bool $reverse, ?array $only = null): void
    {
        $stored = EloquentStoredEvent::query()->where('event_class', $eventAlias)->orderByDesc('id')->firstOrFail()->toStoredEvent();
        $eventClass = $stored->event::class;

        $reactors = app(Projectionist::class)->getReactors()
            ->filter(fn ($reactor): bool => in_array($eventClass, $reactor->handles(), true)
                && ($only === null || in_array($reactor::class, $only, true)))
            ->values();
        $this->assertNotEmpty($reactors->all(), "{$eventAlias} を購読するReactorがありません");

        foreach ($reverse ? $reactors->reverse() : $reactors as $reactor) {
            $reactor->handle($stored);
        }
    }

    /** 指定のイベントについて、逆順・順の両方でReactorを再実行しても状態が変わらないこと。 */
    private function assertReactorsAreIdempotentAndOrderIndependent(array $eventAliases, ?array $only = null): void
    {
        foreach ($eventAliases as $alias) {
            $before = $this->state();

            $this->reprocessReactors($alias, reverse: true, only: $only);
            $this->assertSame($before, $this->state(), "{$alias}: Reactorを逆順に再実行した結果");

            $this->reprocessReactors($alias, reverse: false, only: $only);
            $this->assertSame($before, $this->state(), "{$alias}: Reactorを順に再実行した結果");
        }
    }

    // ---- 午前有給+午後代休 ----

    public function test_a_morning_paid_leave_and_an_afternoon_compensatory_leave_are_both_accepted_and_count_as_a_full_day(): void
    {
        [$employee, $approver] = $this->peopleWithBalances();
        $this->enableCompensatoryLeave('half_day');

        $paidId = $this->requestLeaveOf('paid', $employee, $approver, '2026-09-10', 'am_half');
        $compensatoryId = $this->requestLeaveOf('compensatory', $employee, $approver, '2026-09-10', 'pm_half');

        // 休暇ビュー: 午前の有給と午後の代休が両立する。
        $this->assertSame(2, AttendanceDayLeave::query()->where('user_id', $employee->id)->whereDate('work_date', '2026-09-10')->count());
        $this->assertSame('am_half', AttendanceDayLeave::query()->where('leave_kind', 'paid')->where('leave_request_id', $paidId)->value('unit'));
        $this->assertSame('pm_half', AttendanceDayLeave::query()->where('leave_kind', 'compensatory')->where('leave_request_id', $compensatoryId)->value('unit'));

        // 日次計算: 午前半休+午後半休(種類を問わない)は全休と同じ所定。休暇日数は種類ごとに0.5(代休は日数に数えない)。
        $calc = $this->calcOn($employee, '2026-09-10');
        $this->assertEquals(480, $calc->prescribed_work_minutes);
        $this->assertEquals(0, $calc->work_minutes);
        $this->assertEquals(0.5, $calc->paid_leave_days);
        $this->assertEquals(0.0, $calc->special_leave_days);

        $this->transition('paid', 'approve', $approver, $paidId);
        $this->transition('compensatory', 'approve', $approver, $compensatoryId);

        // 承認しても計算は同じ。両方の休暇が承認済みになり、代休の残数が半日分減る。
        $calc = $this->calcOn($employee, '2026-09-10');
        $this->assertEquals(480, $calc->prescribed_work_minutes);
        $this->assertEquals(0.5, $calc->paid_leave_days);
        $this->assertSame(2, AttendanceDayLeave::query()->where('user_id', $employee->id)->where('request_status', 'approved')->count());
        $this->assertEquals(0.5, (float) CompensatoryLeaveGrant::query()->where('user_id', $employee->id)->sum('remaining_days'));
    }

    // ---- 有給の締め済みの全遷移 ----

    public function test_closed_month_rejects_every_paid_leave_transition_and_no_context_changes(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $this->makeCompensatoryWorkStyle();
        app(CommandBus::class)->dispatch(new GrantPaidLeave($employee->id, '2025-07-01', '2027-06-30', 10.0, null));

        $approvedId = $this->requestLeaveOf('paid', $employee, $approver, '2026-08-10');
        $this->transition('paid', 'approve', $approver, $approvedId);
        $returnedId = $this->requestLeaveOf('paid', $employee, $approver, '2026-08-11');
        $this->transition('paid', 'return', $approver, $returnedId, ['comment' => '日付を見直してください']);
        $pendingId = $this->requestLeaveOf('paid', $employee, $approver, '2026-08-12');

        // 申請中(承認待ち)の有給があると月次提出できない(状態は変わらない)。
        $beforeSubmit = $this->state();
        $this->actingAs($employee)->postJson('/api/attendance/months/2026-08/submit', [
            'approver_user_id' => User::factory()->create()->id,
        ])->assertStatus(422);
        $this->assertSame($beforeSubmit, $this->state());

        // 申請中を取り消してから提出する(差し戻された有給は提出の妨げにならない)。
        $this->transition('paid', 'cancel', $employee, $pendingId);
        $this->actingAs($employee)->postJson('/api/attendance/months/2026-08/submit', [
            'approver_user_id' => User::factory()->create()->id,
        ])->assertSuccessful();

        $this->workDay($employee, '2026-08-13');
        $before = $this->state();

        // 申請
        $this->actingAs($employee)->postJson('/api/paid-leave/requests', [
            'target_date' => '2026-08-13', 'leave_type' => 'full', 'approver_user_id' => $approver->id,
        ])->assertStatus(422);
        $this->assertSame($before, $this->state(), '締め済みの日への申請');

        // 承認(承認済みの再承認)・差戻し(承認済みの差戻し)
        $this->actingAs($approver)->postJson("/api/paid-leave/requests/{$approvedId}/approve")->assertStatus(422);
        $this->assertSame($before, $this->state(), '締め済みの日の承認');
        $this->actingAs($approver)->postJson("/api/paid-leave/requests/{$approvedId}/return", ['comment' => '差戻し'])->assertStatus(422);
        $this->assertSame($before, $this->state(), '締め済みの日の差戻し');

        // 取消(承認済み・差戻し中・ワークフロー側)
        $this->actingAs($employee)->postJson("/api/paid-leave/requests/{$approvedId}/cancel")->assertStatus(422);
        $this->assertSame($before, $this->state(), '締め済みの日の取消(承認済み)');
        $this->actingAs($employee)->postJson("/api/paid-leave/requests/{$returnedId}/cancel")->assertStatus(422);
        $this->assertSame($before, $this->state(), '締め済みの日の取消(差戻し中)');
        $this->actingAs($employee)->postJson('/api/workflow-requests/'.$this->workflowIdOf($returnedId).'/cancel', ['reason' => '取り下げ'])->assertStatus(422);
        $this->assertSame($before, $this->state(), '締め済みの日の取消(ワークフロー側)');

        // 再提出
        $this->actingAs($employee)->postJson('/api/workflow-requests/'.$this->workflowIdOf($returnedId).'/submit')->assertStatus(422);
        $this->assertSame($before, $this->state(), '締め済みの日の再提出');
    }

    // ---- 承認不要の申請 ----

    public function test_a_special_leave_request_without_approval_is_approved_at_once_and_reaches_every_context(): void
    {
        SystemSetting::current()->update(['special_leave_requires_approval' => false]);
        [$employee] = $this->peopleWithBalances();

        $requestId = $this->requestLeaveOf('special', $employee, null, '2026-09-10');

        // ワークフローは経由せず、即承認。
        $this->assertSame(0, WorkflowRequest::query()->where('subject_id', $requestId)->count());
        $this->assertSame('approved', $this->specialLeaveOn($employee->id, '2026-09-10')->request_status);

        // 消化の確定: 消化記録が確定し、特別休暇の残数が減る(未充当なし)。
        $usages = SpecialLeaveUsage::query()->where('special_leave_request_id', $requestId)->get();
        $this->assertCount(1, $usages);
        $this->assertTrue((bool) $usages->first()->is_confirmed);
        $this->assertEquals(0.0, (float) $usages->first()->unallocated_days);
        $this->assertEquals(9.0, (float) SpecialLeaveGrant::query()->where('user_id', $employee->id)->sum('remaining_days'));

        // 勤怠: 勤怠日が作られ、日次計算に特別休暇の全休が入る。
        $calc = $this->calcOn($employee, '2026-09-10');
        $this->assertNotNull($calc);
        $this->assertEquals(1.0, $calc->special_leave_days);
        $this->assertEquals(480, $calc->prescribed_work_minutes);
        $this->assertSame(0, AttendanceSpecialLeaveShortage::query()->where('unallocated_days', '>', 0)->count());
    }

    public function test_a_compensatory_leave_request_without_approval_is_approved_at_once_and_reaches_every_context(): void
    {
        [$employee] = $this->peopleWithBalances();
        SystemSetting::current()->update(['compensatory_leave_requires_approval' => false]);

        $requestId = $this->requestLeaveOf('compensatory', $employee, null, '2026-09-10');

        $this->assertSame(0, WorkflowRequest::query()->where('subject_id', $requestId)->count());
        $this->assertSame('approved', AttendanceDayLeave::query()
            ->where('leave_kind', 'compensatory')->where('leave_request_id', $requestId)->value('request_status'));

        // 消化の確定: 代休の残数(休日出勤の付与1日)が使われる。
        $this->assertEquals(0.0, (float) CompensatoryLeaveGrant::query()->where('user_id', $employee->id)->sum('remaining_days'));
        $this->assertSame(1, CompensatoryLeaveUsage::query()->where('compensatory_leave_request_id', $requestId)->count());

        // 勤怠: 勤怠日が作られ、全休の所定で日次計算される。
        $calc = $this->calcOn($employee, '2026-09-10');
        $this->assertNotNull($calc);
        $this->assertEquals(480, $calc->prescribed_work_minutes);
        $this->assertEquals(0, $calc->work_minutes);
    }

    // ---- Reactorの冪等性・実行順 ----

    public function test_reprocessing_the_reactors_of_the_same_event_changes_nothing_for_every_leave_kind(): void
    {
        [$employee, $approver] = $this->peopleWithBalances();

        $aliases = [
            'paid' => ['paid_leave_request.requested', 'paid_leave_request.shared', 'paid_leave_request.approved', 'paid_leave_request.returned', 'paid_leave_request.cancelled'],
            'special' => ['special_leave.requested', 'special_leave.request_shared', 'special_leave.request_approved', 'special_leave.request_returned', 'special_leave.request_cancelled'],
            'compensatory' => ['compensatory_leave.requested', 'compensatory_leave.request_shared', 'compensatory_leave.request_approved', 'compensatory_leave.request_returned', 'compensatory_leave.request_cancelled'],
        ];

        $dates = ['paid' => '2026-09-14', 'special' => '2026-09-15', 'compensatory' => '2026-09-16'];

        foreach (self::KINDS as $kind => $_) {
            [$requested, $shared, $approved, $returned, $cancelled] = $aliases[$kind];
            $date = $dates[$kind];

            // 申請(requested・sharedの後にワークフローが提出済みになる)
            $requestId = $this->requestLeaveOf($kind, $employee, $approver, $date);
            $this->assertReactorsAreIdempotentAndOrderIndependent([$requested, $shared]);
            $this->assertSame('submitted', WorkflowRequest::query()->where('subject_id', $requestId)->value('status'));
            $this->assertSame(1, $this->notificationCount($approver, self::KINDS[$kind]['label'].'の承認依頼'));

            // 承認
            $this->transition($kind, 'approve', $approver, $requestId);
            $this->assertReactorsAreIdempotentAndOrderIndependent([$approved]);

            // 差戻し(別の申請)・取消(別の申請)
            $returnedId = $this->requestLeaveOf($kind, $employee, $approver, date('Y-m-d', strtotime("{$date} +7 days")));
            $this->transition($kind, 'return', $approver, $returnedId, ['comment' => 'やり直し']);
            $this->assertReactorsAreIdempotentAndOrderIndependent([$returned]);

            $cancelledId = $this->requestLeaveOf($kind, $employee, $approver, date('Y-m-d', strtotime("{$date} +14 days")));
            $this->transition($kind, 'cancel', $employee, $cancelledId);
            $this->assertReactorsAreIdempotentAndOrderIndependent([$cancelled]);
        }
    }

    public function test_reprocessing_the_compensatory_grant_reactors_changes_nothing(): void
    {
        $employee = User::factory()->create();
        $this->holidayWorkGrantedAndConfirmed($employee, ['2026-08-08', '2026-08-09']);

        // 休日出勤の計算イベント(付与の同期)と月次提出(付与の確定)を再処理しても、付与も記録されるイベントも変わらない。
        $this->assertReactorsAreIdempotentAndOrderIndependent(
            ['attendance_day.calculated', 'attendance_month.submitted'],
            [SyncCompensatoryLeaveAccountGrantOnAttendanceDayCalculatedReactor::class, ConfirmCompensatoryLeaveGrantsOnAttendanceMonthSubmittedReactor::class],
        );

        $this->assertSame(2, CompensatoryLeaveGrant::query()->where('user_id', $employee->id)->where('status', 'confirmed')->count());
    }

    // ---- 通知が重複しない ----

    public function test_notifications_are_sent_once_for_request_approve_return_and_cancel_of_every_leave_kind(): void
    {
        [$employee, $approver] = $this->peopleWithBalances();
        $dates = ['paid' => '2026-09-14', 'special' => '2026-09-15', 'compensatory' => '2026-09-16'];

        foreach (self::KINDS as $kind => $config) {
            $label = $config['label'];
            $date = $dates[$kind];

            // 申請: 承認者へ承認依頼が1通。申請者へは届かない。
            $approvedId = $this->requestLeaveOf($kind, $employee, $approver, $date);
            $this->assertSame(1, $this->notificationCount($approver, "{$label}の承認依頼"), "{$kind}: 申請");
            $this->assertSame(0, $this->notificationCount($employee, "{$label}の承認依頼"), "{$kind}: 申請(申請者)");

            // 承認: 申請者へ承認の通知が1通。
            $this->transition($kind, 'approve', $approver, $approvedId);
            $this->assertSame(1, $this->notificationCount($employee, "{$label}が承認されました"), "{$kind}: 承認");

            // 差戻し: 申請者へ差戻しの通知が1通(休暇側では送らない)。リンクは種類ごとの画面。
            $returnedId = $this->requestLeaveOf($kind, $employee, $approver, date('Y-m-d', strtotime("{$date} +7 days")));
            $this->transition($kind, 'return', $approver, $returnedId, ['comment' => 'やり直し']);
            $this->assertSame(1, $this->notificationCount($employee, "{$label}が差戻されました"), "{$kind}: 差戻し");
            $expectedSuffix = ['paid' => '/paid-leave/history', 'special' => '/special-leave/history', 'compensatory' => '/compensatory-leave'][$kind];
            $url = Notification::query()->where('recipient_user_id', $employee->id)->where('title', "{$label}が差戻されました")->value('detail_url');
            $this->assertStringEndsWith($expectedSuffix, $url);

            // 取消: 承認者へ取消の通知が1通ずつ増えるだけ(申請者へは届かない)。
            $cancelBefore = $this->notificationCount($approver, '申請取消');
            $cancelledId = $this->requestLeaveOf($kind, $employee, $approver, date('Y-m-d', strtotime("{$date} +14 days")));
            $this->transition($kind, 'cancel', $employee, $cancelledId);
            $this->assertSame($cancelBefore + 1, $this->notificationCount($approver, '申請取消'), "{$kind}: 取消");
        }

        // 種類ごとの承認依頼は、申請の件数(3件ずつ)と同じだけ。重複して届いていない。
        foreach (self::KINDS as $config) {
            $this->assertSame(3, $this->notificationCount($approver, $config['label'].'の承認依頼'));
        }
    }

    // ---- 勤怠側の特別休暇の残数不足ビュー ----

    public function test_the_special_leave_shortage_view_is_built_from_the_account_events_and_rebuilds_the_same(): void
    {
        $employee = User::factory()->create();
        $approver = User::factory()->create();
        $this->makeCompensatoryWorkStyle();
        $type = SpecialLeaveType::query()->create(['name' => '夏季休暇', 'is_active' => true, 'requires_grant' => true]);
        $this->specialType = $type;

        // 付与が無いまま承認する(残数不足でも承認し、未充当を記録する)。
        $requestId = $this->requestLeaveOf('special', $employee, $approver, '2026-08-10');
        $this->assertSame(0, AttendanceSpecialLeaveShortage::query()->where('unallocated_days', '>', 0)->count(), '申請中は未充当を持たない');

        $this->transition('special', 'approve', $approver, $requestId);

        $shortage = AttendanceSpecialLeaveShortage::query()->where('special_leave_request_id', $requestId)->first();
        $this->assertNotNull($shortage);
        $this->assertSame($employee->id, $shortage->user_id);
        $this->assertSame('2026-08-10', $shortage->work_date);
        $this->assertSame($type->id, $shortage->special_leave_type_id);
        $this->assertEquals(1.0, (float) $shortage->unallocated_days);

        // 月次APIの警告はこのビューから作る(応答の形は変えない)。
        $response = $this->actingAs($employee)->getJson('/api/attendance/months/2026-08')->assertOk();
        $this->assertSame($requestId, $response->json('special_leave_balance_warnings.0.special_leave_request_id'));
        $this->assertSame('夏季休暇', $response->json('special_leave_balance_warnings.0.special_leave_type_name'));
        $this->assertSame('2026-08-10', $response->json('special_leave_balance_warnings.0.used_on'));
        $this->assertEquals(1.0, $response->json('special_leave_balance_warnings.0.requested_days'));

        // ビューを空にしてProjectorを再生成しても同じ。
        $expected = AttendanceSpecialLeaveShortage::query()->orderBy('usage_id')->get()->toArray();
        AttendanceSpecialLeaveShortage::query()->delete();
        Artisan::call('projections:rebuild', ['projector' => 'AttendanceSpecialLeaveShortageProjector']);
        $this->assertSame($expected, AttendanceSpecialLeaveShortage::query()->orderBy('usage_id')->get()->toArray());

        // 承認後の取消で、ビューの行は消え、警告も出なくなる。
        $this->transition('special', 'cancel', $employee, $requestId);
        $this->assertSame(0, AttendanceSpecialLeaveShortage::query()->count());
        $this->actingAs($employee)->getJson('/api/attendance/months/2026-08')->assertOk()
            ->assertJsonCount(0, 'special_leave_balance_warnings');
    }
}
