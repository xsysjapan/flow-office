<?php

namespace App\Http\Controllers\Api;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\PaidLeaveSchedule\Aggregates\PaidLeaveScheduleAggregate;
use App\Domain\PaidLeaveSchedule\Commands\ApplyScheduledGrants;
use App\Domain\PaidLeaveSchedule\Commands\ManuallyEditScheduleEntry;
use App\Domain\PaidLeaveSchedule\Commands\OverrideScheduleAssessment;
use App\Domain\PaidLeaveSchedule\Commands\RunAttendanceRateAssessment;
use App\Domain\PaidLeaveSchedule\Support\AttendanceRateAssessor;
use App\Domain\PaidLeaveSchedule\Support\PaidLeaveScheduleAssessmentPlanner;
use App\Http\Controllers\Controller;
use App\Models\PaidLeaveScheduleEntry;
use App\Support\BusinessDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

/**
 * 有給付与予定Schedule/Assessmentの管理API
 * (docs/changesets/20260906-paid-leave-schedule-assessment/spec.md 実装対象Phase D)。
 * 一覧・詳細はProjection Table(`paid_leave_schedule_entries`/`paid_leave_schedule_assessments`)を
 * 読み取り、再判定・Override・一括付与は対応するCommandをCommandBus経由で発行する
 * (ルートCLAUDE.md原則1「状態変更はCommand→CommandHandler→stored_eventsで行う」)。
 * すべて管理者専用(`permission:leave.manage`、routes/api.php参照)。
 */
#[OA\Tag(name: '有給休暇付与予定', description: '有給付与Schedule/Assessment管理(付与予定一覧・再判定・Override・一括付与)')]
class PaidLeaveScheduleController extends Controller
{
    private const FILTER_ALL = 'all';

    private const FILTER_ELIGIBLE = 'eligible';

    private const FILTER_NOT_ELIGIBLE = 'not_eligible';

    private const FILTER_NEEDS_REVIEW = 'needs_review';

    private const FILTER_CHANGED = 'changed';

    private const FILTER_OVERDUE = 'overdue';

    /**
     * 付与予定一覧(依頼書§39・spec.md論点12)。フィルタ:
     * すべて/付与対象(eligible)/対象外(not_eligible)/要確認(needs_review)/変更あり(changed)/
     * 付与予定日超過・未付与(overdue、spec.md論点8)。
     * 社員名(user_name、部分一致)・付与予定日の期間(scheduled_on_from/to、両端含む)は
     * 状態フィルタとANDで組み合わせる(spec.md論点4)。
     *
     * 「変更あり」の定義について: `PaidLeaveScheduleAggregate::recalculateFutureSchedule()`は
     * 個別修正済み(`is_manually_overridden=true`)エントリを再計算対象から除外して保護するが
     * (spec.md論点7)、Phase Aの時点では「保護対象から除外する」ことのみが保証されており、
     * 保護後に条件変更があったかどうかの機械的な食い違い検知(NeedsReviewへの強制遷移)は
     * Phase C以降の課題として未実装のまま残っている
     * (`PaidLeaveScheduleAggregate::recalculateFutureSchedule()`のコメント参照)。
     * そのため本フィルタは「個別修正が行われており、以後のルール・条件変更の影響を
     * 受けずに固定されている(=管理者が変更の有無を目視確認すべき)エントリ」として
     * `is_manually_overridden=true`を基準に実装する。
     */
    #[OA\Get(
        path: '/paid-leave/schedule-entries',
        operationId: 'paidLeave.schedule.index',
        summary: '有給付与予定Scheduleエントリ一覧を取得する',
        tags: ['有給休暇付与予定'],
        parameters: [
            new OA\Parameter(name: 'filter', in: 'query', schema: new OA\Schema(type: 'string', enum: ['all', 'eligible', 'not_eligible', 'needs_review', 'changed', 'overdue'])),
            new OA\Parameter(name: 'user_name', in: 'query', schema: new OA\Schema(type: 'string', maxLength: 200), description: '社員名の部分一致'),
            new OA\Parameter(name: 'scheduled_on_from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date'), description: '付与予定日の開始日(含む)'),
            new OA\Parameter(name: 'scheduled_on_to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date'), description: '付与予定日の終了日(含む)。scheduled_on_from以上'),
        ],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Forbidden'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'filter' => ['nullable', Rule::in([
                self::FILTER_ALL,
                self::FILTER_ELIGIBLE,
                self::FILTER_NOT_ELIGIBLE,
                self::FILTER_NEEDS_REVIEW,
                self::FILTER_CHANGED,
                self::FILTER_OVERDUE,
            ])],
            'user_name' => ['nullable', 'string', 'max:200'],
            'scheduled_on_from' => ['nullable', 'date'],
            'scheduled_on_to' => ['nullable', 'date', 'after_or_equal:scheduled_on_from'],
        ]);

        $filter = $data['filter'] ?? self::FILTER_ALL;

        $query = PaidLeaveScheduleEntry::query()->with('user', 'assessments')->orderBy('scheduled_on')->orderBy('nominal_service_months');

        match ($filter) {
            self::FILTER_ELIGIBLE => $query->where('status', PaidLeaveScheduleAggregate::STATUS_ELIGIBLE),
            self::FILTER_NOT_ELIGIBLE => $query->where('status', PaidLeaveScheduleAggregate::STATUS_NOT_ELIGIBLE),
            self::FILTER_NEEDS_REVIEW => $query->where('status', PaidLeaveScheduleAggregate::STATUS_NEEDS_REVIEW),
            self::FILTER_CHANGED => $query->where('is_manually_overridden', true),
            self::FILTER_OVERDUE => $query->overdue(BusinessDate::today()),
            default => null,
        };

        $query
            // LIKEのワイルドカード(`%`・`_`)と、エスケープ文字自身(`\`)を文字どおりに扱う。
            // sqliteはLIKEにデフォルトのエスケープ文字が無いため`ESCAPE`句を明示する
            // (MySQLでも同じ書き方で動く)。Laravelの`whereLike`は値をエスケープしないため使わない。
            ->when($data['user_name'] ?? null, fn ($query, $name) => $query->whereHas(
                'user',
                fn ($userQuery) => $userQuery->whereRaw(
                    'users.name LIKE ? ESCAPE ?',
                    ['%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $name).'%', '\\'],
                ),
            ))
            // scheduled_onは`date`キャストだが、sqlite上は日付部分だけを保証しない
            // 生文字列(datetime相当)で保存されうるため、日付部分だけを`whereDate`で比較する
            // (素の文字列比較では期間の開始日=終了日の境界値が一致しなくなる。E2E
            // `scenario-15-paid-leave-schedule.spec.ts`で発見)。
            ->when(
                $data['scheduled_on_from'] ?? null,
                fn ($query, $from) => $query->whereDate('scheduled_on', '>=', $from),
            )
            ->when(
                $data['scheduled_on_to'] ?? null,
                fn ($query, $to) => $query->whereDate('scheduled_on', '<=', $to),
            );

        return response()->json([
            'data' => $query->get()->map(fn (PaidLeaveScheduleEntry $entry) => $this->summarize($entry)),
        ]);
    }

    /**
     * 詳細(依頼書§40)。Assessment履歴(分母/分子/除外日内訳)を含む。
     */
    #[OA\Get(
        path: '/paid-leave/schedule-entries/{scheduleEntry}',
        operationId: 'paidLeave.schedule.show',
        summary: '有給付与予定Scheduleエントリの詳細・Assessment履歴を取得する',
        tags: ['有給休暇付与予定'],
        parameters: [new OA\Parameter(name: 'scheduleEntry', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Forbidden')],
    )]
    public function show(PaidLeaveScheduleEntry $scheduleEntry): JsonResponse
    {
        $scheduleEntry->load('user', 'assessments');

        return response()->json([
            'data' => array_merge($this->summarize($scheduleEntry), [
                'is_manually_overridden' => $scheduleEntry->is_manually_overridden,
                'manual_override_reason' => $scheduleEntry->manual_override_reason,
                'cancelled_reason' => $scheduleEntry->cancelled_reason,
                'grant_id' => $scheduleEntry->grant_id,
                'assessments' => $scheduleEntry->assessments->map(fn ($assessment) => [
                    'id' => $assessment->id,
                    'period_start' => $assessment->period_start?->toDateString(),
                    'period_end' => $assessment->period_end?->toDateString(),
                    'denominator_days' => $assessment->denominator_days,
                    'attendance_days' => $assessment->attendance_days,
                    'excluded_days' => $assessment->excluded_days,
                    'deemed_attendance_days' => $assessment->deemed_attendance_days,
                    'attendance_rate' => $assessment->attendance_rate,
                    'policy_version' => $assessment->policy_version,
                    'automatic_result' => $assessment->automatic_result,
                    'final_result' => $assessment->final_result,
                    'override_reason' => $assessment->override_reason,
                    'overridden_by_user_id' => $assessment->overridden_by_user_id,
                    'created_at' => $assessment->created_at?->toIso8601String(),
                ]),
            ]),
        ]);
    }

    /**
     * 再判定(依頼書§40「再判定」導線)。判定の内容(期間・みなし出勤・閾値)は
     * `PaidLeaveScheduleAssessmentPlanner`が決め(docs/changesets/20261021-paid-leave-fiscal-mass-grant-and-auto-assessment/spec.md
     * 論点5・10)、`RunAttendanceRateAssessment`を発行する。まとめて付与する2件(組)は、2行をそれぞれ判定して両方記録する
     * (1つのトランザクション。片方が失敗したら両方記録しない)。
     */
    #[OA\Post(
        path: '/paid-leave/schedule-entries/{scheduleEntry}/reassess',
        operationId: 'paidLeave.schedule.reassess',
        summary: '出勤率を再判定する',
        tags: ['有給休暇付与予定'],
        parameters: [new OA\Parameter(name: 'scheduleEntry', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Forbidden'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function reassess(PaidLeaveScheduleEntry $scheduleEntry, CommandBus $commandBus, PaidLeaveScheduleAssessmentPlanner $planner): JsonResponse
    {
        $scheduleEntry->loadMissing('user');
        $user = $scheduleEntry->user;

        $partnerId = PaidLeaveScheduleAggregate::retrieve(PaidLeaveScheduleAggregate::streamIdFor($user->id))
            ->forUser($user->id)
            ->pairedEntryId($scheduleEntry->id);

        $targets = collect([$scheduleEntry]);
        if ($partnerId !== null) {
            $partner = PaidLeaveScheduleEntry::query()->find($partnerId);
            if ($partner !== null) {
                $targets->push($partner);
            }
        }

        DB::transaction(function () use ($targets, $user, $planner, $commandBus): void {
            foreach ($targets as $target) {
                $plan = $planner->plan($target, $user);

                $commandBus->dispatch(new RunAttendanceRateAssessment(
                    userId: $user->id,
                    scheduleEntryId: $target->id,
                    assessmentId: (string) Str::uuid(),
                    periodStart: $plan->periodStart,
                    periodEnd: $plan->periodEnd,
                    denominatorDays: $plan->denominatorDays,
                    attendanceDays: $plan->attendanceDays,
                    excludedDays: $plan->excludedDays,
                    attendanceRate: $plan->attendanceRate,
                    policyVersion: AttendanceRateAssessor::POLICY_VERSION,
                    automaticResult: $plan->automaticResult,
                    deemedAttendanceDays: $plan->deemedAttendanceDays,
                ));
            }
        });

        return response()->json(['data' => $this->summarize($scheduleEntry->refresh()->load('user', 'assessments'))]);
    }

    /**
     * 判定結果の上書き(依頼書§40「判定結果を上書き」)。理由は必須(Field Error)。
     */
    #[OA\Post(
        path: '/paid-leave/schedule-entries/{scheduleEntry}/override',
        operationId: 'paidLeave.schedule.override',
        summary: '出勤率判定結果を上書きする',
        tags: ['有給休暇付与予定'],
        parameters: [new OA\Parameter(name: 'scheduleEntry', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['final_result', 'reason'], properties: [new OA\Property(property: 'final_result', type: 'string', enum: ['Eligible', 'NotEligible']), new OA\Property(property: 'reason', type: 'string')])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Forbidden'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function override(Request $request, PaidLeaveScheduleEntry $scheduleEntry, CommandBus $commandBus): JsonResponse
    {
        $data = $request->validate([
            'final_result' => ['required', Rule::in([
                PaidLeaveScheduleAggregate::STATUS_ELIGIBLE,
                PaidLeaveScheduleAggregate::STATUS_NOT_ELIGIBLE,
            ])],
            'reason' => ['required', 'string'],
        ]);

        $scheduleEntry->loadMissing('user');

        $commandBus->dispatch(new OverrideScheduleAssessment(
            userId: $scheduleEntry->user_id,
            scheduleEntryId: $scheduleEntry->id,
            finalResult: $data['final_result'],
            reason: $data['reason'],
            operatorUserId: $request->user()->id,
        ));

        return response()->json(['data' => $this->summarize($scheduleEntry->refresh()->load('user', 'assessments'))]);
    }

    /**
     * 区分・候補付与日数の個別修正(UC-P014後半、spec.md論点3)。付与予定日は変更対象にせず、
     * 送られても無視する。理由は必須。送られたキーだけを`ManuallyEditScheduleEntry`の`changes`に入れる。
     * 確定・取消済みの行は集約の`DomainRuleException`で422になる。
     */
    #[OA\Patch(
        path: '/paid-leave/schedule-entries/{scheduleEntry}',
        operationId: 'paidLeave.schedule.update',
        summary: '付与予定の区分・候補付与日数を個別修正する',
        tags: ['有給休暇付与予定'],
        parameters: [new OA\Parameter(name: 'scheduleEntry', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['reason'], properties: [
            new OA\Property(property: 'category', type: 'string', enum: ['normal', 'proportional', 'shift'], nullable: true, description: '区分。candidate_grant_daysと少なくとも一方が必須'),
            new OA\Property(property: 'candidate_grant_days', type: 'number', format: 'float', minimum: 0.5, nullable: true, description: '候補付与日数(0.5日単位)。categoryと少なくとも一方が必須'),
            new OA\Property(property: 'reason', type: 'string', maxLength: 1000),
        ])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Forbidden'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function update(Request $request, PaidLeaveScheduleEntry $scheduleEntry, CommandBus $commandBus): JsonResponse
    {
        $data = $request->validate([
            'category' => ['nullable', 'required_without:candidate_grant_days', Rule::in([
                'normal',
                'proportional',
                'shift',
            ])],
            'candidate_grant_days' => ['nullable', 'required_without:category', 'numeric', 'min:0.5', 'multiple_of:0.5'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $changes = [];
        if (isset($data['category'])) {
            $changes['category'] = $data['category'];
        }
        if (isset($data['candidate_grant_days'])) {
            $changes['candidateGrantDays'] = (float) $data['candidate_grant_days'];
        }

        $scheduleEntry->loadMissing('user');

        $commandBus->dispatch(new ManuallyEditScheduleEntry(
            userId: $scheduleEntry->user_id,
            scheduleEntryId: $scheduleEntry->id,
            changes: $changes,
            reason: $data['reason'],
            operatorUserId: $request->user()->id,
        ));

        return response()->json(['data' => $this->summarize($scheduleEntry->refresh()->load('user', 'assessments'))]);
    }

    /**
     * 一括付与(依頼書§39)。複数社員のエントリを一括で受け付け、社員(Aggregate)単位で
     * `ApplyScheduledGrants`を発行する。既存`ManualGrantCard`の`runBulkGrant`/
     * `ResultSummary`パターン(全体件数+行単位の成功/失敗)を踏襲したレスポンス形状にする
     * (spec.md論点15-6)。Eligible以外のエントリはCommandHandler側で当該エントリのみ
     * 失敗として扱われ、他のエントリには影響しない。
     */
    #[OA\Post(
        path: '/paid-leave/schedule-entries/bulk-grant',
        operationId: 'paidLeave.schedule.bulkGrant',
        summary: '選択したScheduleエントリを一括付与する',
        tags: ['有給休暇付与予定'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['schedule_entry_ids'], properties: [new OA\Property(property: 'schedule_entry_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'))])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Forbidden'), new OA\Response(response: 422, description: 'Validation error(まとめて付与する2件の片方だけが指定された場合を含む)')],
    )]
    public function bulkGrant(Request $request, CommandBus $commandBus): JsonResponse
    {
        $data = $request->validate([
            'schedule_entry_ids' => ['required', 'array', 'min:1'],
            'schedule_entry_ids.*' => ['required', 'string', 'exists:paid_leave_schedule_entries,id'],
        ]);

        $entries = PaidLeaveScheduleEntry::query()
            ->whereIn('id', $data['schedule_entry_ids'])
            ->get()
            ->groupBy('user_id');

        // まとめて付与する2件(組)は片方だけ付与できない(docs/changesets/20261021-paid-leave-fiscal-mass-grant-and-auto-assessment/spec.md 論点10)。
        foreach ($entries as $userId => $userEntries) {
            $aggregate = PaidLeaveScheduleAggregate::retrieve(PaidLeaveScheduleAggregate::streamIdFor((string) $userId))->forUser((string) $userId);
            $selectedIds = $userEntries->pluck('id')->all();

            foreach ($selectedIds as $selectedId) {
                $partnerId = $aggregate->pairedEntryId($selectedId);

                if ($partnerId !== null && ! in_array($partnerId, $selectedIds, true)) {
                    throw ValidationException::withMessages([
                        'schedule_entry_ids' => ['まとめて付与する2件は、同時に選んでください。'],
                    ]);
                }
            }
        }

        $results = [];
        $successCount = 0;
        $failureCount = 0;

        foreach ($entries as $userId => $userEntries) {
            $outcome = $commandBus->dispatch(new ApplyScheduledGrants(
                userId: (string) $userId,
                scheduleEntryIds: $userEntries->pluck('id')->all(),
                operatorUserId: $request->user()->id,
            ));

            foreach ($outcome['granted'] as $granted) {
                $results[] = [
                    'schedule_entry_id' => $granted['scheduleEntryId'],
                    'success' => true,
                    'message' => null,
                    'grant_id' => $granted['grantId'],
                ];
                $successCount++;
            }

            foreach ($outcome['failed'] as $failed) {
                $results[] = [
                    'schedule_entry_id' => $failed['scheduleEntryId'],
                    'success' => false,
                    'message' => $failed['reason'],
                    'grant_id' => null,
                ];
                $failureCount++;
            }
        }

        return response()->json([
            'success_count' => $successCount,
            'failure_count' => $failureCount,
            'results' => $results,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summarize(PaidLeaveScheduleEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'user_id' => $entry->user_id,
            'user_name' => $entry->user?->name,
            'scheduled_on' => $entry->scheduled_on?->toDateString(),
            'category' => $entry->category,
            'candidate_grant_days' => $entry->candidate_grant_days,
            'nominal_service_months' => $entry->nominal_service_months,
            'status' => $entry->status,
            'latest_assessment_id' => $entry->latest_assessment_id,
            'is_manually_overridden' => $entry->is_manually_overridden,
            // 一覧の「出勤率」列(フロントエンドPaidLeaveSchedulePage)用。最新Assessmentの値を
            // そのまま返す(まだ判定が実行されていないエントリはnull)。
            'attendance_rate' => $entry->relationLoaded('assessments')
                ? $entry->assessments->last()?->attendance_rate
                : null,
        ];
    }
}
