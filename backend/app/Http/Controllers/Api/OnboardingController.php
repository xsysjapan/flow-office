<?php

namespace App\Http\Controllers\Api;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\User\Commands\CompleteOnboardingWithLocalPassword;
use App\Domain\User\Commands\StartOnboardingSso;
use App\Domain\User\Ms365ConfigResolver;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Laravel\Socialite\Facades\Socialite;
use OpenApi\Attributes as OA;

/**
 * 初回オンボーディング(docs/06-usecases-auth.md UC-000): 認証はEntra ID SSOが原則だが、
 * SSO自体が空の間は誰もSSOログインできず管理画面にも到達できない「鶏と卵」を解消するため、
 * 未認証で呼び出せる。2つのモードがある。
 *
 * - SSOモード: `storeSso()`でMicrosoft 365資格情報を保存し、実際にEntra IDへログインして
 *   もらう(`AuthController::callback()`が`SSO_LINK_STATE`を見て`CompleteOnboardingSsoLink`
 *   を発行する)。管理者になるユーザーはOIDCの認証結果でのみ決まり、メールアドレスの
 *   事前入力や文字列一致には依存しない。
 * - ローカルモード: `storeLocal()`でその場でパスワード付きの管理者ユーザーを作成する。
 *
 * いずれも`system_settings.onboarding_completed_at`が設定済みなら以後の実行は拒否する。
 */
#[OA\Tag(name: '初回オンボーディング', description: 'Microsoft 365連携設定またはローカルパスワードでの最初の管理者作成')]
class OnboardingController extends Controller
{
    /** Socialiteの認可リクエストに載せ、コールバックで初回オンボーディングのSSOリンク
     *  完了処理だと判別するための`state`値(CSRF用の値ではなく単なる経路の目印)。 */
    public const SSO_LINK_STATE = 'onboarding-sso-link';

    #[OA\Get(
        path: '/onboarding/status',
        operationId: 'onboarding.status',
        summary: '初回オンボーディングが必要かどうかを取得する',
        tags: ['初回オンボーディング'],
        responses: [new OA\Response(response: 200, description: 'Successful response')],
    )]
    public function status(): JsonResponse
    {
        $settings = SystemSetting::current();

        return response()->json([
            'needs_onboarding' => $settings->onboarding_completed_at === null,
            'sso_configured' => $settings->m365Configured(),
        ]);
    }

    #[OA\Post(
        path: '/onboarding/sso',
        operationId: 'onboarding.storeSso',
        summary: '初回オンボーディング(SSOモード)を開始し、Microsoftログインへのリダイレクト先を取得する',
        tags: ['初回オンボーディング'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['m365_tenant_id', 'm365_client_id', 'm365_client_secret'],
                properties: [
                    new OA\Property(property: 'm365_tenant_id', type: 'string'),
                    new OA\Property(property: 'm365_client_id', type: 'string'),
                    new OA\Property(property: 'm365_client_secret', type: 'string'),
                    new OA\Property(property: 'm365_mock_enabled', type: 'boolean'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Successful response'),
            new OA\Response(response: 422, description: 'Validation error, or onboarding already started/completed'),
        ],
    )]
    public function storeSso(Request $request, CommandBus $commandBus): JsonResponse
    {
        $data = $request->validate([
            'm365_tenant_id' => ['required', 'string'],
            'm365_client_id' => ['required', 'string'],
            'm365_client_secret' => ['required', 'string'],
            'm365_mock_enabled' => ['boolean'],
        ]);

        $commandBus->dispatch(new StartOnboardingSso(
            m365TenantId: $data['m365_tenant_id'],
            m365ClientId: $data['m365_client_id'],
            m365ClientSecret: $data['m365_client_secret'],
            m365MockEnabled: $data['m365_mock_enabled'] ?? false,
        ));

        Ms365ConfigResolver::applyToSocialiteConfig();

        $redirectUrl = Socialite::driver('azure')->stateless()
            ->with(['state' => self::SSO_LINK_STATE])
            ->redirect()
            ->getTargetUrl();

        return response()->json(['redirect_url' => $redirectUrl]);
    }

    #[OA\Post(
        path: '/onboarding/local',
        operationId: 'onboarding.storeLocal',
        summary: '初回オンボーディング(ローカルパスワードモード)を完了する',
        tags: ['初回オンボーディング'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['admin_name', 'admin_email', 'admin_password'],
                properties: [
                    new OA\Property(property: 'admin_name', type: 'string'),
                    new OA\Property(property: 'admin_email', type: 'string'),
                    new OA\Property(property: 'admin_password', type: 'string'),
                ],
            ),
        ),
        responses: [
            new OA\Response(response: 200, description: 'Successful response'),
            new OA\Response(response: 422, description: 'Validation error, or onboarding already started/completed'),
        ],
    )]
    public function storeLocal(Request $request, CommandBus $commandBus): JsonResponse
    {
        $data = $request->validate([
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email'],
            'admin_password' => ['required', 'string', Password::min(8)],
        ]);

        $user = $commandBus->dispatch(new CompleteOnboardingWithLocalPassword(
            adminName: $data['admin_name'],
            adminEmail: $data['admin_email'],
            adminPassword: $data['admin_password'],
        ));

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user->load('roles')),
        ]);
    }
}
