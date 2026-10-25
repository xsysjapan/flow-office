<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminCommandRun;
use App\Services\EventSourcing\ReadModelRebuildRunService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * ReadModelの作り直し(全テーブルを空にして全Projectorをreplay)の依頼と実行状況
 * (docs/changesets/20261025-admin-read-model-rebuild/spec.md 論点5)。
 * 依頼は記録(`queued`)を作るだけで、実行は`read-models:rebuild --pending`(スケジューラー)が行う。
 * すべて`read_model.rebuild`(global。routes/api.php)。
 */
#[OA\Tag(name: 'データの作り直し', description: 'ReadModelの作り直し(管理者)とメンテナンス状態')]
class ReadModelRebuildController extends Controller
{
    #[OA\Get(
        path: '/admin/read-model-rebuilds',
        operationId: 'readModelRebuilds.index',
        summary: '作り直しの実行の履歴(新しい順・直近20件。summaryは含めない)を取得する',
        tags: ['データの作り直し'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Forbidden')],
    )]
    public function index(ReadModelRebuildRunService $service): JsonResponse
    {
        return response()->json([
            'data' => $service->recentRuns(20)->map(fn (AdminCommandRun $run) => $this->present($run, false))->values()->all(),
        ]);
    }

    #[OA\Get(
        path: '/admin/read-model-rebuilds/{id}',
        operationId: 'readModelRebuilds.show',
        summary: '作り直しの実行を1件(summary付き)取得する',
        tags: ['データの作り直し'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Forbidden'), new OA\Response(response: 404, description: 'Not found')],
    )]
    public function show(string $id, ReadModelRebuildRunService $service): JsonResponse
    {
        $run = $service->findRun($id);
        abort_if($run === null, 404);

        return response()->json(['data' => $this->present($run, true)]);
    }

    #[OA\Post(
        path: '/admin/read-model-rebuilds',
        operationId: 'readModelRebuilds.store',
        summary: 'ReadModelの作り直しを依頼する(受付のみ。実行はスケジューラーが行う)',
        tags: ['データの作り直し'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['dry_run'], properties: [new OA\Property(property: 'dry_run', type: 'boolean', description: 'trueなら試し実行(最後にロールバックする)')])),
        responses: [new OA\Response(response: 202, description: 'Accepted'), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Forbidden'), new OA\Response(response: 409, description: '実行中・待機中の作り直しがある'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function store(Request $request, ReadModelRebuildRunService $service): JsonResponse
    {
        $data = $request->validate(['dry_run' => ['required', 'boolean']]);

        $run = $service->createRun($request->user(), (bool) $data['dry_run']);

        if ($run === null) {
            return response()->json(['message' => ReadModelRebuildRunService::ACTIVE_MESSAGE], 409);
        }

        return response()->json(['data' => $this->present($run->load('requestedByUser:id,name'), true)], 202);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(AdminCommandRun $run, bool $withSummary): array
    {
        return [
            'id' => $run->id,
            'dry_run' => (bool) ($run->parameters['dry_run'] ?? false),
            'status' => $run->status,
            'requested_by' => $run->requestedByUser !== null
                ? ['id' => $run->requestedByUser->id, 'name' => $run->requestedByUser->name]
                : null,
            'requested_at' => $run->created_at?->toIso8601String(),
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'error_message' => $run->error_message,
            'summary' => $withSummary ? $run->summary : null,
        ];
    }
}
