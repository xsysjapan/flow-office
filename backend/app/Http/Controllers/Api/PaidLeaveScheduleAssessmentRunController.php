<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminCommandRun;
use App\Services\PaidLeave\ScheduleAssessmentRunService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * 有給付与予定の出勤率の自動判定の実行状況と、手動実行の受付
 * (docs/changesets/20261021-paid-leave-fiscal-mass-grant-and-auto-assessment/spec.md 論点6・7)。
 * 手動の受付は記録(`queued`)を作るだけで、実行は`paid-leave:assess-schedules --pending`(スケジューラー)が行う。
 * すべて`leave.manage`(routes/api.php)。
 */
#[OA\Tag(name: '有給休暇付与予定', description: '有給付与Schedule/Assessment管理(付与予定一覧・再判定・Override・一括付与・自動判定)')]
class PaidLeaveScheduleAssessmentRunController extends Controller
{
    #[OA\Get(
        path: '/paid-leave/schedule-assessment-runs/status',
        operationId: 'paidLeave.scheduleAssessmentRuns.status',
        summary: '自動判定の次回実行予定と、最新の実行状況を取得する',
        tags: ['有給休暇付与予定'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Forbidden')],
    )]
    public function status(ScheduleAssessmentRunService $service): JsonResponse
    {
        $latest = $service->latestRun();

        return response()->json([
            'data' => [
                'next_run_at' => $service->nextRunAt()->toIso8601String(),
                'latest_run' => $latest !== null ? $this->present($latest) : null,
            ],
        ]);
    }

    #[OA\Post(
        path: '/paid-leave/schedule-assessment-runs',
        operationId: 'paidLeave.scheduleAssessmentRuns.store',
        summary: '自動判定を手動で実行する(受付のみ。実行はスケジューラーが行う)',
        tags: ['有給休暇付与予定'],
        responses: [new OA\Response(response: 202, description: 'Accepted'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Forbidden'), new OA\Response(response: 409, description: '実行中・待機中の判定がある')],
    )]
    public function store(Request $request, ScheduleAssessmentRunService $service): JsonResponse
    {
        $run = $service->createManualRun($request->user());

        if ($run === null) {
            return response()->json(['message' => '判定を実行中です。終わってから実行してください。'], 409);
        }

        return response()->json(['data' => $this->present($run->load('requestedByUser:id,name'))], 202);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(AdminCommandRun $run): array
    {
        return [
            'id' => $run->id,
            'status' => $run->status,
            'trigger' => $run->parameters['trigger'] ?? null,
            'requested_by_name' => $run->requestedByUser?->name,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'summary' => $run->summary,
            'error_message' => $run->error_message,
        ];
    }
}
