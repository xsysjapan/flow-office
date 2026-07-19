<?php

use App\Http\Controllers\Mcp\McpController;
use App\Http\Controllers\OAuth\ClientRegistrationController;
use App\Http\Controllers\OAuth\MetadataController;
use App\Http\Controllers\OAuth\TokenController;
use App\Http\Middleware\EnsureMcpAccessToken;
use Illuminate\Support\Facades\Route;

// Dynamic Client Registration (RFC 7591)。
Route::post('/oauth/register', [ClientRegistrationController::class, 'store']);

// アクセストークン発行(認可コード交換・リフレッシュ)。
Route::post('/oauth/token', [TokenController::class, 'issue']);

// MCP(JSON-RPC 2.0、Streamable HTTP非ストリーミング応答)。マウントパス直下('/')が
// そのままJSON-RPCエンドポイントになる(mcp/routes/web.php参照)。
Route::post('/', [McpController::class, 'handle'])->middleware(EnsureMcpAccessToken::class);

// MCPクライアントの自動発見用メタデータ(RFC 8414 / RFC 9728)。
Route::get('/.well-known/oauth-authorization-server', [MetadataController::class, 'authorizationServer']);
Route::get('/.well-known/oauth-protected-resource', [MetadataController::class, 'protectedResource']);
