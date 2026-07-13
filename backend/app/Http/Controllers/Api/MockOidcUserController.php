<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * mock-oidc(ローカル開発用OIDCモックサーバー、mock-oidc/server.js)がログイン画面の
 * ユーザー選択肢をDBから動的に取得するための開発専用エンドポイント。
 * `MICROSOFT_MOCK_ENABLED=true` の時のみ有効(本番・検証環境では404)。認証は不要
 * (ログイン前のモック認可画面から呼ばれるため)。
 */
class MockOidcUserController extends Controller
{
    public function index(): JsonResponse
    {
        if (! config('services.azure.mock_enabled')) {
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
