<?php

namespace App\Http\Controllers\Api;

use App\Domain\User\SsoAuthenticator;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

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
 */
class AuthController extends Controller
{
    private const EXCHANGE_CACHE_PREFIX = 'sso-exchange:';

    public function redirect(): JsonResponse
    {
        $url = Socialite::driver('azure')->stateless()->redirect()->getTargetUrl();

        return response()->json(['url' => $url]);
    }

    public function callback(SsoAuthenticator $authenticator): RedirectResponse
    {
        $ssoUser = Socialite::driver('azure')->stateless()->user();
        $user = $authenticator->handle($ssoUser);

        $exchangeCode = Str::random(40);
        Cache::put(self::EXCHANGE_CACHE_PREFIX.$exchangeCode, $user->id, now()->addSeconds(60));

        return redirect()->away(
            rtrim(config('app.frontend_url'), '/').'/auth/callback?code='.$exchangeCode
        );
    }

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

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load('roles'));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'ログアウトしました。']);
    }
}
