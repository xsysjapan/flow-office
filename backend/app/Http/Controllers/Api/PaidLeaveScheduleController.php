<?php

namespace App\Http\Controllers\Api;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\PaidLeaveSchedule\Aggregates\PaidLeaveScheduleAggregate;
use App\Domain\PaidLeaveSchedule\Commands\ApplyScheduledGrants;
use App\Domain\PaidLeaveSchedule\Commands\OverrideScheduleAssessment;
use App\Domain\PaidLeaveSchedule\Commands\RunAttendanceRateAssessment;
use App\Domain\PaidLeaveSchedule\Support\AttendanceRateAssessor;
use App\Http\Controllers\Controller;
use App\Models\PaidLeaveGrant;
use App\Models\PaidLeaveGrantRule;
use App\Models\PaidLeaveScheduleEntry;
use App\Models\UserWorkStyleMonthlyAssignment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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

    /**
     * 付与予定一覧(依頼書§39・spec.md論点12)。5フィルタ:
     * すべて/付与対象(eligible)/対象外(not_eligible)/要確認(needs_review)/変更あり(changed)。
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
        parameters: [new OA\Parameter(name: 'filter', in: 'query', schema: new OA\Schema(type: 'string', enum: ['all', 'eligible', 'not_eligible', 'needs_review', 'changed']))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Forbidden')],
    )]
    public function index(Request $request): JsonResponse
    {
        $filter = $request->query('filter', self::FILTER_ALL);

        $query = PaidLeaveScheduleEntry::query()->with('user', 'assessments')->orderBy('scheduled_on');

        match ($filter) {
            self::FILTER_ELIGIBLE => $query->where('status', PaidLeaveScheduleAggregate::STATUS_ELIGIBLE),
            self::FILTER_NOT_ELIGIBLE => $query->where('status', PaidLeaveScheduleAggregate::STATUS_NOT_ELIGIBLE),
            self::FILTER_NEEDS_REVIEW => $query->where('status', PaidLeaveScheduleAggregate::STATUS_NEEDS_REVIEW),
            self::FILTER_CHANGED => $query->where('is_manually_overridden', true),
            default => null,
        };

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
     * 再判定(依頼書§40「再判定」導線)。`AttendanceRateAssessor`を同一条件で再実行し、
     * `RunAttendanceRateAssessment`を発行する。対象社員に適用中のルール
     * (`paid_leave_grant_rules`。無ければ既定値)から`min_attendance_rate`/
     * `grant_cycle_months`を解決し、判定期間は`[scheduled_on - grant_cycle_months, scheduled_on]`
     * とする(`GrantScheduledPaidLeaveHandler::meetsAttendanceRate()`と同じ期間の考え方)。
     */
    #[OA\Post(
        path: '/paid-leave/schedule-entries/{scheduleEntry}/reassess',
        operationId: 'paidLeave.schedule.reassess',
        summary: '出勤率を再判定する',
        tags: ['有給休暇付与予定'],
        parameters: [new OA\Parameter(name: 'scheduleEntry', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Forbidden'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function reassess(PaidLeaveScheduleEntry $scheduleEntry, CommandBus $commandBus, AttendanceRateAssessor $assessor): JsonResponse
    {
        $scheduleEntry->loadMissing('user');
        $user = $scheduleEntry->user;

        [$minAttendanceRate, $grantCycleMonths] = $this->resolveAssessmentParameters($user->id);

        $periodEnd = $scheduleEntry->scheduled_on->copy();
        $periodStart = $periodEnd->copy()->subMonths($grantCycleMonths);

        $hasMigratedLegacyData = PaidLeaveGrant::query()
            ->where('user_id', $user->id)
            ->whereNotNull('cutover_metadata')
            ->exists();

        $result = $assessor->assess(
            userId: $user->id,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            minAttendanceRate: $minAttendanceRate,
            usageStartDate: $user->usage_start_date,
            hasMigratedLegacyData: $hasMigratedLegacyData,
        );

        $commandBus->dispatch(new RunAttendanceRateAssessment(
            userId: $user->id,
            scheduleEntryId: $scheduleEntry->id,
            assessmentId: (string) Str::uuid(),
            periodStart: $periodStart->toDateString(),
            periodEnd: $periodEnd->toDateString(),
            denominatorDays: $result->denominatorDays,
            attendanceDays: $result->attendanceDays,
            excludedDays: $result->excludedDays,
            attendanceRate: $result->attendanceRate,
            policyVersion: AttendanceRateAssessor::POLICY_VERSION,
            automaticResult: $result->automaticResult,
        ));

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
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Forbidden'), new OA\Response(response: 422, description: 'Validation error')],
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

    /**
     * 対象社員の現在の`WorkStyle`割当に一致する`paid_leave_grant_rules`
     * (`is_active=true`)から`min_attendance_rate`/`grant_cycle_months`を解決する
     * (`ScheduleCandidateGenerator::currentWorkStyleFor()`と同じ「現在時点で有効な
     * user_work_style_monthly_assignments」を採用する考え方)。該当ルールが無ければ
     * 既定値(80%・12ヶ月、現行`GrantScheduledPaidLeaveHandler`の既定と同じ)を使う。
     *
     * @return array{0: int, 1: int} [minAttendanceRate, grantCycleMonths]
     */
    private function resolveAssessmentParameters(string $userId): array
    {
        $currentYearMonth = Carbon::today()->format('Y-m');

        $assignment = UserWorkStyleMonthlyAssignment::query()
            ->where('user_id', $userId)
            ->where('year_month', '<=', $currentYearMonth)
            ->orderByDesc('year_month')
            ->first();

        $rule = $assignment !== null
            ? PaidLeaveGrantRule::query()
                ->where('work_style_id', $assignment->work_style_id)
                ->where('is_active', true)
                ->first()
            : null;

        return [
            (int) ($rule->min_attendance_rate ?? 80),
            max(1, (int) ($rule->grant_cycle_months ?? 12)),
        ];
    }
}
