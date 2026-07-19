<?php

namespace App\Http\Controllers\OAuth;

use App\Mcp\OAuth\Entities\UserEntity;
use App\Models\McpUserBackendToken;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response as Psr7Response;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * OAuth2認可エンドポイント(GET/POST /oauth/authorize)。Claude等のMCPクライアントから
 * ブラウザリダイレクトされた人間に、要求スコープを人間可読な説明で確認させる
 * (docs/25-usecases-integrations-mcp.md UC-I001 手順6と同じ体裁)。
 *
 * 人間の識別は、mcp/自身のログイン機構ではなく「/link で貼り付けたbackendの個人連携
 * トークン」に基づくセッション(session('mcp_user_id'))で行う。
 */
class AuthorizeController extends Controller
{
    private const PASSTHROUGH_FIELDS = [
        'response_type', 'client_id', 'redirect_uri', 'scope', 'state',
        'code_challenge', 'code_challenge_method',
    ];

    public function __construct(private readonly AuthorizationServer $server)
    {
    }

    public function show(Request $request): SymfonyResponse|RedirectResponse
    {
        if (! session()->has('mcp_user_id')) {
            return redirect()->route('link.show', ['redirect' => $request->fullUrl()]);
        }

        try {
            $psrRequest = (new Psr17Factory)->createServerRequest('GET', $request->fullUrl())
                ->withQueryParams($request->query());
            $authRequest = $this->server->validateAuthorizationRequest($psrRequest);
        } catch (OAuthServerException $e) {
            return (new HttpFoundationFactory)->createResponse($e->generateHttpResponse(new Psr7Response));
        }

        $requestedScopes = array_map(fn ($scope) => $scope->getIdentifier(), $authRequest->getScopes());

        $backendToken = McpUserBackendToken::query()->where('mcp_user_id', session('mcp_user_id'))->first();
        $grantedScopes = $backendToken?->granted_scopes ?? [];
        $missingScopes = array_diff($requestedScopes, $grantedScopes);

        if ($missingScopes !== []) {
            return response()->view('oauth.scope-error', [
                'missingScopes' => $missingScopes,
                'scopeLabels' => config('mcp.scopes'),
            ], 403);
        }

        return response()->view('oauth.authorize', [
            'clientName' => $authRequest->getClient()->getName(),
            'scopes' => $requestedScopes,
            'scopeLabels' => config('mcp.scopes'),
            'fields' => $request->only(self::PASSTHROUGH_FIELDS),
        ]);
    }

    public function approve(Request $request): SymfonyResponse
    {
        return $this->respond($request, approved: (bool) $request->input('approve'));
    }

    private function respond(Request $request, bool $approved): SymfonyResponse
    {
        $queryParams = $request->only(self::PASSTHROUGH_FIELDS);
        $uri = '/oauth/authorize?'.http_build_query($queryParams);

        try {
            $psrRequest = (new Psr17Factory)->createServerRequest('GET', $uri)->withQueryParams($queryParams);
            $authRequest = $this->server->validateAuthorizationRequest($psrRequest);
        } catch (OAuthServerException $e) {
            return (new HttpFoundationFactory)->createResponse($e->generateHttpResponse(new Psr7Response));
        }

        $authRequest->setUser(new UserEntity((string) session('mcp_user_id')));
        $authRequest->setAuthorizationApproved($approved);

        try {
            $psrResponse = $this->server->completeAuthorizationRequest($authRequest, new Psr7Response);
        } catch (OAuthServerException $e) {
            $psrResponse = $e->generateHttpResponse(new Psr7Response);
        }

        return (new HttpFoundationFactory)->createResponse($psrResponse);
    }
}
