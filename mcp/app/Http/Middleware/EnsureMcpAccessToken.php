<?php

namespace App\Http\Middleware;

use App\Models\McpUserBackendToken;
use Closure;
use Illuminate\Http\Request;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Response;

/**
 * /mcp へのリクエストを、mcp/自身が発行したOAuth2アクセストークン(Bearer)で検証する
 * リソースサーバー。検証OK後、トークンに紐づくmcp_user → mcp_user_backend_tokens の
 * backend Sanctumトークンを解決し、後続のMcpControllerが使えるようリクエスト属性に積む。
 */
class EnsureMcpAccessToken
{
    public function __construct(private readonly ResourceServer $resourceServer)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $psrHttpFactory = new PsrHttpFactory;
        $psrRequest = $psrHttpFactory->createRequest($request);

        $wwwAuthenticate = sprintf(
            'Bearer resource_metadata="%s/.well-known/oauth-protected-resource"',
            rtrim(config('app.url'), '/'),
        );

        try {
            $validated = $this->resourceServer->validateAuthenticatedRequest($psrRequest);
        } catch (OAuthServerException $e) {
            return response()->json([
                'error' => 'invalid_token',
                'error_description' => $e->getMessage(),
            ], 401)->header('WWW-Authenticate', $wwwAuthenticate);
        }

        $mcpUserId = $validated->getAttribute('oauth_user_id');
        $scopes = $validated->getAttribute('oauth_scopes', []);

        $backendToken = $mcpUserId !== null
            ? McpUserBackendToken::query()->where('mcp_user_id', $mcpUserId)->first()
            : null;

        if ($backendToken === null) {
            return response()->json([
                'error' => 'invalid_token',
                'error_description' => 'flow-officeの連携トークンが紐付けられていません。/link で紐付けてください。',
            ], 401)->header('WWW-Authenticate', $wwwAuthenticate);
        }

        $backendToken->forceFill(['last_used_at' => now()])->save();

        $request->attributes->set('mcp_oauth_scopes', $scopes);
        $request->attributes->set('mcp_backend_token', $backendToken->getPlainToken());
        $request->attributes->set('mcp_user_id', $mcpUserId);

        return $next($request);
    }
}
