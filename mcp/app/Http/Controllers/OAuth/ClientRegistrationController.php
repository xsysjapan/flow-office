<?php

namespace App\Http\Controllers\OAuth;

use App\Http\Controllers\Controller;
use App\Models\OauthClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Dynamic Client Registration (RFC 7591)。ClaudeなどのMCPクライアントが事前登録なしに
 * 自己登録できるようにする。本サーバーは公開クライアント(client_secretを発行しない、
 * PKCE必須)のみをサポートするため、token_endpoint_auth_methodは常に`none`に固定する。
 */
class ClientRegistrationController extends Controller
{
    private const ALLOWED_GRANT_TYPES = ['authorization_code', 'refresh_token'];

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'client_name' => ['nullable', 'string', 'max:255'],
            'redirect_uris' => ['required', 'array', 'min:1'],
            'redirect_uris.*' => ['required', 'url'],
            'grant_types' => ['nullable', 'array'],
            'grant_types.*' => ['string', 'in:'.implode(',', self::ALLOWED_GRANT_TYPES)],
            'token_endpoint_auth_method' => ['nullable', 'string', 'in:none'],
        ]);

        $grantTypes = $data['grant_types'] ?? self::ALLOWED_GRANT_TYPES;
        if (array_diff($grantTypes, self::ALLOWED_GRANT_TYPES) !== []) {
            throw ValidationException::withMessages([
                'grant_types' => 'grant_typesは authorization_code / refresh_token のみサポートします。',
            ]);
        }

        $client = OauthClient::query()->create([
            'client_id' => 'mcp_'.Str::random(40),
            'client_name' => $data['client_name'] ?? 'Unnamed MCP Client',
            'redirect_uris' => $data['redirect_uris'],
            'grant_types' => $grantTypes,
            'token_endpoint_auth_method' => 'none',
        ]);

        return response()->json([
            'client_id' => $client->client_id,
            'client_id_issued_at' => $client->created_at->timestamp,
            'client_name' => $client->client_name,
            'redirect_uris' => $client->redirect_uris,
            'grant_types' => $client->grant_types,
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ], 201);
    }
}
