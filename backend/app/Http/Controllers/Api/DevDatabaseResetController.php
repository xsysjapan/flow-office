<?php

namespace App\Http\Controllers\Api;

use App\Domain\UserManagement\Ms365ConfigResolver;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * E2Eテスト(Playwright, frontend/e2e/)の実行開始時にDBを既知の初期状態へ戻すための
 * 開発専用エンドポイント。`MockOidcUserController`と全く同じ考え方
 * (`Ms365ConfigResolver::mockEnabled()`がfalseなら404)で本番・検証環境からは
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
        if (! Ms365ConfigResolver::mockEnabled()) {
            throw new NotFoundHttpException;
        }

        // E2E用の全マイグレーション・全Seederは、開発端末によって30秒を超える。
        // モック有効時だけ到達可能な開発専用処理なので、リセット完了まで時間制限を設けない。
        set_time_limit(0);

        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);

        // ScenarioAccessSeeder を適用する前の製品初期状態を返し、E2E の globalSetup で
        // AccessControlSeeder が意図する標準初期値(ALL_USERSへFeature9件・RoleAssignment1件、
        // docs/31-user-group-access-foundation.md 31.1節参照)からズレていないかを検証できる
        // ようにする(数値そのものはAccessControlSeederの`$initialFeatures`が正)。
        $allUsersGroupId = DB::table('groups')->where('code', 'ALL_USERS')->value('id');
        $productInitialAccess = [
            'all_users_feature_assignments' => $allUsersGroupId === null
                ? 0
                : DB::table('group_feature_assignments')->where('group_id', $allUsersGroupId)->count(),
            'all_users_role_assignments' => $allUsersGroupId === null
                ? 0
                : DB::table('role_assignments')->where('subject_type', 'group')->where('subject_id', $allUsersGroupId)->count(),
        ];
        Artisan::call('db:seed', ['--class' => 'ScenarioSeeder', '--force' => true]);

        return response()->json(['status' => 'ok', 'product_initial_access' => $productInitialAccess]);
    }
}
