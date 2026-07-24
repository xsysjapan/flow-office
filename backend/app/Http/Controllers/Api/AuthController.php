<?php

namespace App\Http\Controllers\Api;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\User\Commands\CompleteOnboardingSsoLink;
use App\Domain\User\Commands\LinkSsoAccount;
use App\Domain\User\Commands\RecordLocalLogin;
use App\Domain\User\Ms365ConfigResolver;
use App\Domain\User\SsoAuthenticator;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use OpenApi\Attributes as OA;

/**
 * UC-001: Microsoft SSOでログインする。
 *
 * フロントエンドとバックエンドを分離しているため、以下の2段階で認証する。
 * 1. GET /auth/microsoft/redirect でMicrosoftログインURLを取得しブラウザを遷移させる
 * 2. Microsoftからのリダイレクト (GET /auth/microsoft/callback) をこのAPIが受け、
 *    ワンタイムの exchange code を発行してフロントエンドへリダイレクトする
 * 3. フロントエンドは POST /auth/token で exchange code をSanctumトークンに交換する
 *
 * これにより、長期利用可能なAPIトークンがURLに直接載ることを避ける。
 *
 * `callback()`は初回オンボーディングのSSOモード(UC-000、`OnboardingController::SSO_LINK_STATE`)
 * からも呼ばれる共通の入口。`state`クエリパラメータでどちらの経路かを判定する
 * (`OnboardingController::storeSso()`が`Socialite::with(['state' => ...])`で付与する)。
 */
#[OA\Tag(name: '認証', description: 'Microsoft SSO・ローカルパスワード・Sanctumトークン認証')]
class AuthController extends Controller
{
    private const EXCHANGE_CACHE_PREFIX = 'sso-exchange:';

    /** UC-004: state値のプレフィックス。後続の暗号化文字列にログイン中ユーザーのIDを載せる。 */
    private const SSO_LINK_STATE_PREFIX = 'link-sso:';

    #[OA\Get(
        path: '/auth/microsoft/redirect',
        operationId: 'auth.microsoft.redirect',
        summary: 'MicrosoftログインURLを取得する',
        tags: ['認証'],
        responses: [new OA\Response(response: 200, description: 'Successful response')],
    )]
    public function redirect(): JsonResponse
    {
        Ms365ConfigResolver::applyToSocialiteConfig();

        $url = Socialite::driver('azure')->stateless()->redirect()->getTargetUrl();

        return response()->json(['url' => $url]);
    }

    #[OA\Get(
        path: '/auth/microsoft/link-redirect',
        operationId: 'auth.microsoft.linkRedirect',
        summary: 'ログイン中ユーザーにMicrosoft 365アカウントを紐づけるためのログインURLを取得する(UC-004)',
        tags: ['認証'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function linkRedirect(Request $request): JsonResponse
    {
        Ms365ConfigResolver::applyToSocialiteConfig();

        // 紐づけ対象のユーザーIDを暗号化してstateに載せる。OAuthリダイレクトを挟んで
        // 戻ってきた際、フロントエンドのセッション情報には頼れないため
        // (callback()はSanctum認証されていないリクエストとして届く)。
        $state = self::SSO_LINK_STATE_PREFIX.Crypt::encryptString((string) $request->user()->id);

        $url = Socialite::driver('azure')->stateless()
            ->with(['state' => $state])
            ->redirect()
            ->getTargetUrl();

        return response()->json(['url' => $url]);
    }

    #[OA\Get(
        path: '/auth/microsoft/callback',
        operationId: 'auth.microsoft.callback',
        summary: 'Microsoftログイン後のコールバックを処理する',
        tags: ['認証'],
        responses: [new OA\Response(response: 302, description: 'Redirect response'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function callback(SsoAuthenticator $authenticator, CommandBus $commandBus, Request $request): RedirectResponse
    {
        Ms365ConfigResolver::applyToSocialiteConfig();

        $ssoUser = Socialite::driver('azure')->stateless()->user();
        $state = $request->query('state');

        if ($state === OnboardingController::SSO_LINK_STATE) {
            $user = $commandBus->dispatch(new CompleteOnboardingSsoLink(
                entraUserId: $ssoUser->getId(),
                name: $ssoUser->getName() ?? $ssoUser->getNickname() ?? $ssoUser->getEmail(),
                email: $ssoUser->getEmail(),
            ));
        } elseif (is_string($state) && str_starts_with($state, self::SSO_LINK_STATE_PREFIX)) {
            $userId = Crypt::decryptString(substr($state, strlen(self::SSO_LINK_STATE_PREFIX)));
            $user = $commandBus->dispatch(new LinkSsoAccount(
                userId: $userId,
                entraUserId: $ssoUser->getId(),
            ));
        } else {
            $user = $authenticator->handle($ssoUser);
        }

        $exchangeCode = Str::random(40);
        Cache::put(self::EXCHANGE_CACHE_PREFIX.$exchangeCode, $user->id, now()->addSeconds(60));

        return redirect()->away(
            rtrim(config('app.frontend_url'), '/').'/auth/callback?code='.$exchangeCode
        );
    }

    #[OA\Post(
        path: '/auth/token',
        operationId: 'auth.token',
        summary: '交換コードをAPIトークンに交換する',
        tags: ['認証'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['code'], properties: [new OA\Property(property: 'code', type: 'string')])),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function token(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string']]);

        $cacheKey = self::EXCHANGE_CACHE_PREFIX.$data['code'];
        $userId = Cache::pull($cacheKey);

        if ($userId === null) {
            return response()->json(['message' => '無効または期限切れのコードです。'], 422);
        }

        $user = User::query()->with('roles')->findOrFail($userId);
        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user),
        ]);
    }

    #[OA\Post(
        path: '/auth/local-login',
        operationId: 'auth.localLogin',
        summary: 'ローカルパスワードでログインする(SSOを設定していない場合)',
        tags: ['認証'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'password'],
                properties: [
                    new OA\Property(property: 'email', type: 'string'),
                    new OA\Property(property: 'password', type: 'string'),
                ],
            ),
        ),
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 422, description: 'Validation error')],
    )]
    public function localLogin(Request $request, CommandBus $commandBus): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', $data['email'])->first();

        if ($user === null || $user->password === null || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'メールアドレスまたはパスワードが正しくありません。'], 422);
        }

        $user = $commandBus->dispatch(new RecordLocalLogin(userId: $user->id));
        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user->load('roles')),
        ]);
    }

    #[OA\Get(
        path: '/auth/me',
        operationId: 'auth.me',
        summary: 'ログイン中ユーザーを取得する',
        tags: ['認証'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load('roles'));
    }

    #[OA\Post(
        path: '/auth/logout',
        operationId: 'auth.logout',
        summary: 'ログアウトする',
        tags: ['認証'],
        responses: [new OA\Response(response: 200, description: 'Successful response'), new OA\Response(response: 401, description: 'Unauthenticated')],
    )]
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'ログアウトしました。']);
    }
}
