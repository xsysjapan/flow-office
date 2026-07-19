<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mcp\Support\BackendApiClient;
use App\Mcp\Support\BackendApiException;
use App\Models\McpUser;
use App\Models\McpUserBackendToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * backend/ の POST /users/me/integrations (UC-I001) で発行された個人連携Sanctumトークンを
 * mcp/へ紐付ける初回セットアップ画面。mcp/自身はAzure AD等の認証手段を持たないため、この
 * トークンの検証(GET /auth/me)を人間識別の手段として使う。
 */
class LinkBackendTokenController extends Controller
{
    public function show(Request $request): Response
    {
        return response()->view('oauth.link', [
            'redirect' => $request->query('redirect'),
            'scopeLabels' => config('mcp.scopes'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', 'in:'.implode(',', array_keys(config('mcp.scopes')))],
            'redirect' => ['nullable', 'string'],
        ]);

        $scopes = array_values(array_unique([...$data['scopes'], 'profile:self:read']));

        try {
            $profile = (new BackendApiClient($data['token']))->get('/auth/me');
        } catch (BackendApiException $e) {
            return back()->withErrors(['token' => "flow-officeへの疎通確認に失敗しました(HTTP {$e->status}): {$e->getMessage()}"]);
        }

        $mcpUser = DB::transaction(function () use ($profile, $data, $scopes) {
            $mcpUser = McpUser::query()->updateOrCreate(
                ['email' => $profile['email']],
                ['display_name' => $profile['name'] ?? null],
            );

            $backendToken = $mcpUser->backendToken()->first() ?? new McpUserBackendToken(['mcp_user_id' => $mcpUser->id]);
            $backendToken->setPlainToken($data['token']);
            $backendToken->granted_scopes = $scopes;
            $backendToken->save();

            return $mcpUser;
        });

        session(['mcp_user_id' => $mcpUser->id]);

        $redirect = $this->safeRedirectTarget($data['redirect'] ?? null);

        return $redirect !== null
            ? redirect()->away($redirect)
            : redirect()->route('link.show')->with('status', 'flow-officeとの連携を紐付けました。');
    }

    /**
     * redirectクエリパラメータはURLとして誰でも自由に指定できるため、オープンリダイレクトを
     * 防ぐためこのアプリ自身のホストへのURLのみを許可する(/oauth/authorizeへ戻す用途に限定)。
     */
    private function safeRedirectTarget(?string $redirect): ?string
    {
        if ($redirect === null || $redirect === '') {
            return null;
        }

        $parsed = parse_url($redirect);
        if ($parsed === false || ! isset($parsed['host'])) {
            return null;
        }

        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        return $parsed['host'] === $appHost ? $redirect : null;
    }
}
