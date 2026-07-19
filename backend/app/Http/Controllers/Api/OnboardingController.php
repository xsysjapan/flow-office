<?php

namespace App\Http\Controllers\Api;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\User\Commands\CompleteOnboarding;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * 初回オンボーディング(docs/06-usecases-auth.md): Microsoft 365連携設定(Entra ID
 * アプリ登録の資格情報)の登録と、最初の管理者ユーザー作成を行う。認証はEntra ID SSOのみで
 * ローカルパスワードを持たないため(`App\Models\User`参照)、この連携設定自体が空の間は
 * 誰もSSOログインできず管理画面にも到達できない。この「鶏と卵」を解消するため、
 * 未認証で呼び出せる。`system_settings.onboarding_completed_at`が設定済みなら二度目以降の
 * 実行は拒否する(`CompleteOnboardingHandler`参照)。
 */
#[OA\Tag(name: '初回オンボーディング', description: 'Microsoft 365連携設定と最初の管理者作成')]
class OnboardingController extends Controller
{
    #[OA\Get(
        path: '/onboarding/status',
        operationId: 'onboarding.status',
        summary: '初回オンボーディングが必要かどうかを取得する',
        tags: ['初回オンボーディング'],
        responses: [new OA\Response(response: 200, description: 'Successful response')],
    )]
    public function status(): JsonResponse
    {
        return response()->json([
            'needs_onboarding' => SystemSetting::current()->onboarding_completed_at === null,
        ]);
    }

    #[OA\Post(
        path: '/onboarding',
        operationId: 'onboarding.store',
        summary: '初回オンボーディングを実行する',
        tags: ['初回オンボーディング'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['admin_name', 'admin_email', 'm365_tenant_id', 'm365_client_id', 'm365_client_secret', 'm365_redirect_uri'],
                properties: [
                    new OA\Property(property: 'admin_name', type: 'string'),
                    new OA\Property(property: 'admin_email', type: 'string'),
                    new OA\Property(property: 'm365_tenant_id', type: 'string'),
                    new OA\Property(property: 'm365_client_id', type: 'string'),
                    new OA\Property(property: 'm365_client_secret', type: 'string'),
                    new OA\Property(property: 'm365_redirect_uri', type: 'string'),
                    new OA\Property(property: 'm365_mock_enabled', type: 'boolean'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Successful response'),
            new OA\Response(response: 422, description: 'Validation error, or onboarding already completed'),
        ],
    )]
    public function store(Request $request, CommandBus $commandBus): JsonResponse
    {
        $data = $request->validate([
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email'],
            'm365_tenant_id' => ['required', 'string'],
            'm365_client_id' => ['required', 'string'],
            'm365_client_secret' => ['required', 'string'],
            'm365_redirect_uri' => ['required', 'string'],
            'm365_mock_enabled' => ['boolean'],
        ]);

        $user = $commandBus->dispatch(new CompleteOnboarding(
            adminName: $data['admin_name'],
            adminEmail: $data['admin_email'],
            m365TenantId: $data['m365_tenant_id'],
            m365ClientId: $data['m365_client_id'],
            m365ClientSecret: $data['m365_client_secret'],
            m365RedirectUri: $data['m365_redirect_uri'],
            m365MockEnabled: $data['m365_mock_enabled'] ?? false,
        ));

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user->load('roles')),
        ]);
    }
}
