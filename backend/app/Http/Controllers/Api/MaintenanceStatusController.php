<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EventSourcing\ReadModelRebuildRunService;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

/**
 * メンテナンス中(ReadModelの作り直しの実行中)かを返す。認証不要(フロントがメンテナンスの解除を検知するために読む)。
 */
#[OA\Tag(name: 'データの作り直し', description: 'ReadModelの作り直し(管理者)とメンテナンス状態')]
class MaintenanceStatusController extends Controller
{
    #[OA\Get(
        path: '/maintenance-status',
        operationId: 'maintenanceStatus.show',
        summary: 'メンテナンス中(データの作り直し中)かを取得する',
        tags: ['データの作り直し'],
        responses: [new OA\Response(response: 200, description: 'Successful response')],
    )]
    public function __invoke(ReadModelRebuildRunService $service): JsonResponse
    {
        return response()->json(['maintenance' => $service->isRunning()]);
    }
}
