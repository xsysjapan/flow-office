<?php

namespace App\Http\Controllers\Api;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\PaidLeaveSchedule\Commands\ApplyScheduledGrants;
use App\Domain\PaidLeaveSchedule\Commands\ManuallyEditScheduleEntry;
use App\Domain\PaidLeaveSchedule\Commands\OverrideScheduleAssessment;
use App\Domain\PaidLeaveSchedule\Commands\RunAttendanceRateAssessment;
use App\Domain\PaidLeaveSchedule\Support\ScheduleEntryStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaidLeaveScheduleEntryResource;
use App\Models\PaidLeaveScheduleEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;
use Throwable;

/**
 * 付与予定(Schedule)管理画面向けAPI
 * (docs/changesets/20260906-paid-leave-schedule-assessment/spec.md Phase D)。
 * `paid_leave_schedule_entries`(Projection)を読み取り、`App\Domain\PaidLeaveSchedule`の
 * Commandを発行する。既存`PaidLeaveController`(付与ルール・申請・承認)とは別の新しい
 * リソース領域のため、専用Controllerとして分離する(ルートCLAUDE.md「効率的なコード参照」)。
 */
#[OA\Tag(name: '有給付与予定', description: '付与予定一覧・出勤率Assessment・Override・一括付与')]
class PaidLeaveScheduleController extends Controller
{
    /**
     * spec.md論点12の5フィルタタブに対応する`status`クエリパラメータ。`changed`は
     * 永続化された状態ではなく`PaidLeaveScheduleEntry::needsReviewDueToConflict()`
     * (個別修正済みエントリが再計算でNeedsReviewへ押し出された場合)から導出する
     * 合成フィルタのため、`status`列の値そのものとは別に扱う。
     */
    private const STATUS_FILTERS = ['all', 'eligible', 'not_eligible', 'needs_review', 'changed'];

    #[OA\Get(
        path: '/paid-leave/schedule-entries',
        operationId: 'paidLeave.scheduleEntries.index',
        summary: '付与予定一覧を取得する',
        tags: ['有給付与予定'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, description: 'all(既定)/eligible/not_eligible/needs_review/changed', schema: new OA\Schema(type: 'string', enum: ['all', 'eligible', 'not_eligible', 'needs_review', 'changed'])),
            new OA\Parameter(name: 'user_name', in: 'query', required: false, description: '社員名の部分一致検索', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'scheduled_on_from', in: 'query', required: false, description: '付与予定日(scheduled_on)の期間絞り込み: 開始日(以上)', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'scheduled_on_to', in: 'query', required: false, description: '付与予定日(scheduled_on)の期間絞り込み: 終了日(以下)', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Forbidden'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(self::STATUS_FILTERS)],
            'user_name' => ['nullable', 'string', 'max:200'],
            'scheduled_on_from' => ['nullable', 'date'],
            'scheduled_on_to' => ['nullable', 'date', 'after_or_equal:scheduled_on_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $status = $data['status'] ?? 'all';

        $entries = PaidLeaveScheduleEntry::query()
            ->with('user')
            ->when($data['user_name'] ?? null, fn ($query, $name) => $query->whereHas(
                'user',
                fn ($userQuery) => $userQuery->where('name', 'like', '%'.$name.'%'),
            ))
            ->when($status === 'eligible', fn ($query) => $query->where('status', ScheduleEntryStatus::ELIGIBLE))
            ->when($status === 'not_eligible', fn ($query) => $query->where('status', ScheduleEntryStatus::NOT_ELIGIBLE))
            ->when($status === 'needs_review', fn ($query) => $query->where('status', ScheduleEntryStatus::NEEDS_REVIEW))
            // 「変更あり」(spec.md論点12): 永続化された専用列を持たず、個別修正済み
            // (manual_override_by_user_idあり)かつ再計算でNeedsReviewへ押し出された行を
            // needsReviewDueToConflict()と同じ条件でクエリ側にも表現する。
            ->when($status === 'changed', fn ($query) => $query
                ->where('status', ScheduleEntryStatus::NEEDS_REVIEW)
                ->whereNotNull('manual_override_by_user_id'))
            // scheduled_onは`date`キャストだが、sqlite上は日付部分だけを保証しない
            // 生文字列(datetime相当)で保存されうるため、他コントローラの日付列絞り込みと
            // 同じく`whereDate`で日付部分のみを比較する(`whereBetween`/`where`の素の
            // 文字列比較では、期間の開始日=終了日のような境界値が一致しなくなるバグが
            // あった。E2E `scenario-15-paid-leave-schedule.spec.ts`で発見)。
            ->when(
                $data['scheduled_on_from'] ?? null,
                fn ($query, $from) => $query->whereDate('scheduled_on', '>=', $from),
            )
            ->when(
                $data['scheduled_on_to'] ?? null,
                fn ($query, $to) => $query->whereDate('scheduled_on', '<=', $to),
            )
            ->orderBy('scheduled_on')
            ->paginate($data['per_page'] ?? 50);

        return PaidLeaveScheduleEntryResource::collection($entries);
    }

    #[OA\Get(
        path: '/paid-leave/schedule-entries/{entry}',
        operationId: 'paidLeave.scheduleEntries.show',
        summary: '付与予定の詳細(Assessment内訳)を取得する',
        tags: ['有給付与予定'],
        parameters: [new OA\Parameter(name: 'entry', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Forbidden'), new OA\Response(response: 404, description: 'Not found')],
    )]
    public function show(PaidLeaveScheduleEntry $entry): PaidLeaveScheduleEntryResource
    {
        return new PaidLeaveScheduleEntryResource($entry->load('user'));
    }

    /**
     * 依頼書§40「再判定」: 同一条件で`AttendanceRateAssessor`を再実行する。
     */
    #[OA\Post(
        path: '/paid-leave/schedule-entries/{entry}/reassess',
        operationId: 'paidLeave.scheduleEntries.reassess',
        summary: '付与予定の出勤率Assessmentを再判定する',
        tags: ['有給付与予定'],
        parameters: [new OA\Parameter(name: 'entry', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Forbidden'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function reassess(PaidLeaveScheduleEntry $entry, CommandBus $commandBus): PaidLeaveScheduleEntryResource
    {
        $commandBus->dispatch(new RunAttendanceRateAssessment(
            userId: $entry->user_id,
            entryId: $entry->id,
        ));

        return new PaidLeaveScheduleEntryResource($entry->refresh()->load('user'));
    }

    /**
     * 依頼書§40「判定結果を上書き」: 最終判定をEligible/NotEligibleのいずれかへ確定させ、
     * 理由を必須とする(spec.md画面設計「Override欄」)。
     */
    #[OA\Post(
        path: '/paid-leave/schedule-entries/{entry}/override',
        operationId: 'paidLeave.scheduleEntries.override',
        summary: '付与予定の判定結果を上書きする',
        tags: ['有給付与予定'],
        parameters: [new OA\Parameter(name: 'entry', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['final_result', 'reason'], properties: [new OA\Property(property: 'final_result', type: 'string', enum: ['Eligible', 'NotEligible']), new OA\Property(property: 'reason', type: 'string')])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Forbidden'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function override(Request $request, PaidLeaveScheduleEntry $entry, CommandBus $commandBus): PaidLeaveScheduleEntryResource
    {
        $data = $request->validate([
            'final_result' => ['required', Rule::in([ScheduleEntryStatus::ELIGIBLE, ScheduleEntryStatus::NOT_ELIGIBLE])],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $commandBus->dispatch(new OverrideScheduleAssessment(
            userId: $entry->user_id,
            entryId: $entry->id,
            finalResult: $data['final_result'],
            reason: $data['reason'],
            operatorUserId: $request->user()->id,
        ));

        return new PaidLeaveScheduleEntryResource($entry->refresh()->load('user'));
    }

    /**
     * `App\Domain\PaidLeaveSchedule\Commands\ManuallyEditScheduleEntry`に対応するAPI完全性
     * 目的のエンドポイント。spec.md画面設計(実装前メモ・ワイヤーフレーム)はOverride導線
     * (このentryの`final_result`をEligible/NotEligibleへ確定する操作)のみを具体化しており、
     * 区分・候補日数そのものを直接書き換える専用フォームはUI上まだ設計されていない
     * (spec.md「Phase D」原文コメント参照)。そのため入力を`category`/`candidate_grant_days`
     * (任意項目、どちらか一方の指定でも可)+`reason`必須に絞った最小限の実装とする。
     */
    #[OA\Patch(
        path: '/paid-leave/schedule-entries/{entry}',
        operationId: 'paidLeave.scheduleEntries.manuallyEdit',
        summary: '付与予定を手動で修正する',
        tags: ['有給付与予定'],
        parameters: [new OA\Parameter(name: 'entry', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['reason'], properties: [new OA\Property(property: 'category', type: 'string', nullable: true), new OA\Property(property: 'candidate_grant_days', type: 'number', nullable: true), new OA\Property(property: 'reason', type: 'string')])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Forbidden'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function manuallyEdit(Request $request, PaidLeaveScheduleEntry $entry, CommandBus $commandBus): PaidLeaveScheduleEntryResource
    {
        $data = $request->validate([
            'category' => ['nullable', 'string', 'max:50'],
            'candidate_grant_days' => ['nullable', 'numeric', 'min:0'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $commandBus->dispatch(new ManuallyEditScheduleEntry(
            userId: $entry->user_id,
            entryId: $entry->id,
            category: $data['category'] ?? null,
            candidateGrantDays: isset($data['candidate_grant_days']) ? (float) $data['candidate_grant_days'] : null,
            reason: $data['reason'],
            operatorUserId: $request->user()->id,
        ));

        return new PaidLeaveScheduleEntryResource($entry->refresh()->load('user'));
    }

    /**
     * 依頼書§39「一括付与」: 選択したエントリのうちEligibleのもののみ`GrantPaidLeave`を発行する。
     * `ApplyScheduledGrants`は1社員(Schedule Aggregate)単位のCommandのため
     * (spec.md「ドメインモデル」コメント参照)、複数社員をまたぐ選択はここで`user_id`単位に
     * 束ねてから社員ごとに発行する。1社員分の失敗(Not Eligible混入・Aggregate例外等)が
     * 他社員の処理を止めないよう、`paid-leave:migrate-accounts`と同じ「行単位で成功・失敗を
     * 継続収集する」方針を踏襲し、エントリ単位の結果配列を返す(部分失敗を黙って握りつぶさない)。
     */
    #[OA\Post(
        path: '/paid-leave/schedule-entries/apply-grants',
        operationId: 'paidLeave.scheduleEntries.applyGrants',
        summary: '選択した付与予定を一括付与する',
        tags: ['有給付与予定'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['entry_ids'], properties: [new OA\Property(property: 'entry_ids', type: 'array', items: new OA\Items(type: 'string', format: 'uuid'))])),
        responses: [new OA\Response(response: 200, description: 'Successful response(結果はエントリ単位)'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Forbidden'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function applyGrants(Request $request, CommandBus $commandBus): JsonResponse
    {
        $data = $request->validate([
            'entry_ids' => ['required', 'array', 'min:1'],
            'entry_ids.*' => ['string', 'uuid'],
        ]);

        $entries = PaidLeaveScheduleEntry::query()->whereIn('id', $data['entry_ids'])->get()->keyBy('id');

        $results = [];

        foreach ($data['entry_ids'] as $entryId) {
            $entry = $entries->get($entryId);

            if ($entry === null) {
                $results[] = ['entry_id' => $entryId, 'status' => 'failed', 'error' => 'Scheduleエントリが見つかりません。'];

                continue;
            }

            if ($entry->status !== ScheduleEntryStatus::ELIGIBLE) {
                $results[] = ['entry_id' => $entryId, 'status' => 'failed', 'error' => "Eligibleでないため付与できません(現在: {$entry->status})。"];

                continue;
            }

            try {
                $grantedIds = $commandBus->dispatch(new ApplyScheduledGrants(
                    userId: $entry->user_id,
                    entryIds: [$entryId],
                    operatorUserId: $request->user()->id,
                ));

                $results[] = ['entry_id' => $entryId, 'status' => 'granted', 'grant_id' => $grantedIds[$entryId] ?? null];
            } catch (Throwable $e) {
                $results[] = ['entry_id' => $entryId, 'status' => 'failed', 'error' => $e->getMessage()];
            }
        }

        $successCount = count(array_filter($results, fn (array $r) => $r['status'] === 'granted'));

        return response()->json([
            'results' => $results,
            'success_count' => $successCount,
            'failure_count' => count($results) - $successCount,
        ]);
    }
}
