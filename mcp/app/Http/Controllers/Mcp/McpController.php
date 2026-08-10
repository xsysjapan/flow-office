<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Mcp\Support\BackendApiClient;
use App\Mcp\Support\ToolRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * MCP(Model Context Protocol)のJSON-RPC 2.0エンドポイント(Streamable HTTPの
 * 非ストリーミング応答)。docs/25-usecases-integrations-mcp.md「MCPツール一覧」の
 * ツール群をtools/call経由で公開する。認証はEnsureMcpAccessTokenミドルウェアが担当する。
 */
class McpController extends Controller
{
    public function __construct(private readonly ToolRegistry $tools) {}

    public function handle(Request $request): JsonResponse
    {
        $id = $request->input('id');
        $method = $request->input('method');

        $result = match ($method) {
            'initialize' => $this->initialize(),
            'tools/list' => $this->toolsList($request),
            'tools/call' => $this->toolsCall($request),
            'notifications/initialized', 'ping' => null,
            default => ['__error' => ['code' => -32601, 'message' => "Unknown method: {$method}"]],
        };

        if ($id === null) {
            // 通知(通知にはレスポンスを返さない)。204で応答する。
            return response()->json(null, 204);
        }

        if (is_array($result) && isset($result['__error'])) {
            return response()->json([
                'jsonrpc' => '2.0',
                'id' => $id,
                'error' => $result['__error'],
            ], 200);
        }

        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ]);
    }

    private function initialize(): array
    {
        return [
            'protocolVersion' => '2025-06-18',
            'capabilities' => ['tools' => new \stdClass],
            'serverInfo' => ['name' => 'flow-office-mcp', 'version' => '1.0.0'],
        ];
    }

    private function toolsList(Request $request): array
    {
        $client = new BackendApiClient($request->attributes->get('mcp_backend_token'));
        $access = $client->get('/access/me');

        return [
            'tools' => array_map(fn ($tool) => [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'inputSchema' => $tool->inputSchema(),
            ], array_values(array_filter(
                $this->tools->all(),
                fn ($tool) => $this->hasEffectiveAccess($tool->requiredScopes(), $access),
            ))),
        ];
    }

    private function toolsCall(Request $request): array
    {
        $name = $request->input('params.name');
        $arguments = $request->input('params.arguments', []);

        $tool = $this->tools->find($name);
        if ($tool === null) {
            return ['__error' => ['code' => -32602, 'message' => "Unknown tool: {$name}"]];
        }

        $grantedScopes = $request->attributes->get('mcp_oauth_scopes', []);
        $missingScopes = array_diff($tool->requiredScopes(), $grantedScopes);
        if ($missingScopes !== []) {
            return [
                'content' => [[
                    'type' => 'text',
                    'text' => 'このOAuthトークンには次のスコープが許可されていません: '.implode(', ', $missingScopes),
                ]],
                'isError' => true,
            ];
        }

        $validator = Validator::make($arguments, $this->rulesFromJsonSchema($tool->inputSchema()));
        if ($validator->fails()) {
            return [
                'content' => [['type' => 'text', 'text' => '入力値が不正です: '.$validator->errors()->first()]],
                'isError' => true,
            ];
        }

        $client = new BackendApiClient($request->attributes->get('mcp_backend_token'));
        $access = $client->get('/access/me');
        if (! $this->hasEffectiveAccess($tool->requiredScopes(), $access)) {
            return [
                'content' => [['type' => 'text', 'text' => '現在のFeatureまたはPermissionではこのツールを利用できません。']],
                'isError' => true,
            ];
        }

        return $tool->handle($arguments, $client);
    }

    /** @param string[] $requiredScopes */
    private function hasEffectiveAccess(array $requiredScopes, array $access): bool
    {
        $features = $access['features'] ?? [];
        $permissions = $access['permissions'] ?? [];

        foreach ($requiredScopes as $scope) {
            if (! str_starts_with($scope, 'attendance:')) {
                continue;
            }
            $requiredFeature = str_ends_with($scope, ':clock')
                ? 'attendance.clock'
                : (str_ends_with($scope, ':read') ? null : 'attendance.timesheet');
            if (($requiredFeature !== null && ! in_array($requiredFeature, $features, true))
                || ($requiredFeature === null && ! array_intersect(['attendance.entry', 'attendance.timesheet'], $features))) {
                return false;
            }
            $permission = str_ends_with($scope, ':read') ? 'attendance.read' : 'attendance.update';
            if (! in_array($permission, $permissions, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * ツールのJSON Schema(inputSchema)から、Laravel Validatorが理解できる最低限の
     * ルールへ変換する(型・必須項目のみ。厳密なJSON Schemaバリデーションはしない。
     * 詳細なバリデーションはbackend API側が行う)。
     */
    private function rulesFromJsonSchema(array $schema): array
    {
        $rules = [];
        foreach (($schema['required'] ?? []) as $field) {
            $rules[$field][] = 'required';
        }

        return $rules;
    }
}
