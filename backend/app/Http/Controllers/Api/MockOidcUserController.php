<?php

namespace App\Http\Controllers\Api;

use App\Domain\User\Ms365ConfigResolver;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * mock-oidc(ローカル開発用OIDCモックサーバー、mock-oidc/server.js)がログイン画面の
 * ユーザー選択肢をDBから動的に取得するための開発専用エンドポイント。
 * `system_settings.m365_mock_enabled` (`Ms365ConfigResolver::mockEnabled()`) が
 * trueの時のみ有効(本番・検証環境では404)。認証は不要(ログイン前のモック認可画面から
 * 呼ばれるため)。
 */
#[OA\Tag(name: '開発用認証', description: 'ローカルOIDCモック用API')]
class MockOidcUserController extends Controller
{
    #[OA\Get(
        path: '/dev/mock-users',
        operationId: 'dev.mockUsers.index',
        summary: 'OIDCモック用ユーザー一覧を取得する',
        tags: ['開発用認証'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 404, description: 'Not Found')],
    )]
    public function index(): JsonResponse
    {
        if (! Ms365ConfigResolver::mockEnabled()) {
            throw new NotFoundHttpException;
        }

        $users = User::query()
            ->whereNotNull('entra_user_id')
            ->orderBy('name')
            ->get(['entra_user_id', 'name', 'email', 'department', 'job_title'])
            ->map(fn (User $user) => [
                'id' => $user->entra_user_id,
                'displayName' => $user->name,
                'userPrincipalName' => $user->email,
                'mail' => $user->email,
                'department' => $user->department,
                'jobTitle' => $user->job_title,
            ]);

        return response()->json($users);
    }
}
