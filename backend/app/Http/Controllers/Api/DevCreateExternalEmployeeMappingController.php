<?php

namespace App\Http\Controllers\Api;

use App\Domain\UserManagement\Ms365ConfigResolver;
use App\Http\Controllers\Controller;
use App\Models\ExternalEmployeeMapping;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 外部連携先(freee/マネーフォワード)の従業員番号マッピングを登録するための開発専用
 * エンドポイント。管理画面・管理者向けAPIがまだ無いため(現状はScenarioSeeder/テストの
 * DB直接投入のみ)、Playwright E2E(frontend/e2e/scenario-13-external-integration.spec.ts)が
 * ブラックボックス(HTTP経由)のまま前提データを投入できるように、`DevDatabaseResetController`と
 * 同じ考え方(`Ms365ConfigResolver::mockEnabled()`がfalseなら404)で本番・検証環境からは
 * 到達不能にする。
 */
#[OA\Tag(name: '開発用認証', description: 'ローカルOIDCモック用API')]
class DevCreateExternalEmployeeMappingController extends Controller
{
    #[OA\Post(
        path: '/dev/external-employee-mappings',
        operationId: 'dev.createExternalEmployeeMapping',
        summary: '外部連携先の従業員番号マッピングを登録する(開発/E2E専用)',
        tags: ['開発用認証'],
        responses: [new OA\Response(response: 201, description: 'Created'), new OA\Response(response: 404, description: 'Not Found')],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        if (! Ms365ConfigResolver::mockEnabled()) {
            throw new NotFoundHttpException;
        }

        $data = $request->validate([
            'provider' => ['required', 'string', 'in:freee,moneyforward'],
            'user_id' => ['required', 'string', 'exists:users,id'],
            'external_employee_code' => ['required', 'string'],
        ]);

        $mapping = ExternalEmployeeMapping::query()->updateOrCreate(
            ['provider' => $data['provider'], 'user_id' => $data['user_id']],
            ['id' => (string) Uuid::uuid4(), 'external_employee_code' => $data['external_employee_code']],
        );

        return response()->json(['id' => $mapping->id], 201);
    }
}
