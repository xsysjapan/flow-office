<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use OpenApi\Attributes as OA;

/**
 * E2Eテスト(Playwright, frontend/e2e/)の実行開始時にDBを既知の初期状態へ戻すための
 * 開発専用エンドポイント。`MockOidcUserController`と全く同じ考え方
 * (`config('services.azure.mock_enabled')`がfalseなら404)で本番・検証環境からは
 * 到達不能にする。
 *
 * `migrate:fresh --seed`でスキーマと`DatabaseSeeder`分(ロール・申請種別マスタ・
 * admin@example.com)を作り直した後、`ScenarioSeeder`でシナリオ用マスタデータ
 * (カレンダー・勤務形態・有給付与ルール・登場人物のユーザー・勤務予定・有給付与)を
 * 入れ直す。これにより、開発DBに対して何度E2Eを実行しても常に同じ初期状態から
 * 始まる(frontend/e2e/global-setup.ts参照)。
 */
#[OA\Tag(name: '開発用認証', description: 'ローカルOIDCモック用API')]
class DevDatabaseResetController extends Controller
{
    #[OA\Post(
        path: '/dev/reset-database',
        operationId: 'dev.resetDatabase',
        summary: '開発DBをmigrate:fresh --seed + ScenarioSeederの状態にリセットする',
        tags: ['開発用認証'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 404, description: 'Not Found')],
    )]
    public function __invoke(): JsonResponse
    {
        if (! config('services.azure.mock_enabled')) {
            throw new NotFoundHttpException;
        }

        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
        Artisan::call('db:seed', ['--class' => 'ScenarioSeeder', '--force' => true]);

        return response()->json(['status' => 'ok']);
    }
}
