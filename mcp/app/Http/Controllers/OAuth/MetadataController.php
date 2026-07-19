<?php

namespace App\Http\Controllers\OAuth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * MCPクライアント(Claude等)がDCR・認可・トークンエンドポイントを自動発見するための
 * メタデータ(RFC 8414 Authorization Server Metadata、RFC 9728 Protected Resource
 * Metadata)。MCP仕様の認可フローはこの発見手順を前提とする。
 */
class MetadataController extends Controller
{
    public function authorizationServer(): JsonResponse
    {
        $base = rtrim(config('app.url'), '/');

        return response()->json([
            'issuer' => $base,
            'authorization_endpoint' => "{$base}/oauth/authorize",
            'token_endpoint' => "{$base}/oauth/token",
            'registration_endpoint' => "{$base}/oauth/register",
            'scopes_supported' => array_keys(config('mcp.scopes')),
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'code_challenge_methods_supported' => ['S256', 'plain'],
        ]);
    }

    public function protectedResource(): JsonResponse
    {
        $base = rtrim(config('app.url'), '/');

        return response()->json([
            'resource' => $base,
            'authorization_servers' => [$base],
        ]);
    }
}
