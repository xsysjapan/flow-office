<?php

namespace App\Http\Controllers\Api;

use App\Domain\ExternalIntegration\Aggregates\ExternalIntegrationConnectionAuditAggregate;
use App\Http\Controllers\Controller;
use App\Models\ExternalIntegrationConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Ramsey\Uuid\Uuid;

/**
 * 外部連携(freee/マネーフォワード)設定の管理画面向けAPI。以前はシーダー/DB直接投入
 * 前提だったが、管理者が画面から設定値を登録・有効化して使い始められるようにする
 * (docs/33-usecases-attendance-external-api.md、docs/30-usecases-expense.md参照)。
 *
 * system_settingsと同じ例外パターンで、実データは本Controllerから直接更新するが、
 * 監査目的のイベントは同一トランザクションでstored_eventsに記録する。
 */
class ExternalIntegrationConnectionController extends Controller
{
    private const SECRET_FIELDS = ['access_token', 'refresh_token', 'api_key', 'client_id', 'client_secret'];

    public function index(): JsonResponse
    {
        $connections = ExternalIntegrationConnection::query()->orderBy('provider')->orderBy('created_at')->get();

        return response()->json([
            'data' => $connections->map(fn (ExternalIntegrationConnection $c) => $this->toResource($c))->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'string', Rule::in([
                ExternalIntegrationConnection::PROVIDER_FREEE,
                ExternalIntegrationConnection::PROVIDER_MONEYFORWARD,
            ])],
            'name' => ['required', 'string', 'max:255'],
            'auth_type' => ['required', 'string', Rule::in([
                ExternalIntegrationConnection::AUTH_TYPE_OAUTH2,
                ExternalIntegrationConnection::AUTH_TYPE_API_KEY,
            ])],
            'client_id' => ['nullable', 'string'],
            'client_secret' => ['nullable', 'string'],
            'api_key' => ['nullable', 'string'],
            'external_office_id' => ['nullable', 'string'],
            'enabled' => ['boolean'],
            'custom_settings' => ['nullable', 'array'],
        ]);

        if ($data['auth_type'] === ExternalIntegrationConnection::AUTH_TYPE_OAUTH2) {
            $request->validate([
                'client_id' => ['required', 'string'],
                'client_secret' => ['required', 'string'],
            ]);
        } else {
            $request->validate([
                'api_key' => ['required', 'string'],
            ]);
        }

        $connection = DB::transaction(function () use ($data, $request): ExternalIntegrationConnection {
            $connection = ExternalIntegrationConnection::create([
                'id' => (string) Uuid::uuid4(),
                'provider' => $data['provider'],
                'name' => $data['name'],
                'auth_type' => $data['auth_type'],
                'status' => ExternalIntegrationConnection::STATUS_ACTIVE,
                'enabled' => $data['enabled'] ?? false,
                'client_id' => $data['client_id'] ?? null,
                'client_secret' => $data['client_secret'] ?? null,
                'api_key' => $data['api_key'] ?? null,
                'external_office_id' => $data['external_office_id'] ?? null,
                'custom_settings' => $data['custom_settings'] ?? null,
                'connected_by_user_id' => $request->user()->id,
                'connected_at' => now(),
            ]);

            $after = $this->auditPayload($connection);
            ExternalIntegrationConnectionAuditAggregate::retrieve($connection->id)
                ->recordCreate($after, $request->user()->id)
                ->persist();

            return $connection;
        });

        return response()->json($this->toResource($connection), 201);
    }

    public function update(Request $request, string $externalIntegrationConnection): JsonResponse
    {
        $connection = ExternalIntegrationConnection::query()->findOrFail($externalIntegrationConnection);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'enabled' => ['sometimes', 'boolean'],
            'client_id' => ['sometimes', 'nullable', 'string'],
            'client_secret' => ['sometimes', 'nullable', 'string'],
            'api_key' => ['sometimes', 'nullable', 'string'],
            'external_office_id' => ['sometimes', 'nullable', 'string'],
            'custom_settings' => ['sometimes', 'nullable', 'array'],
        ]);

        // 機密値は空文字/未入力(ConvertEmptyStringsToNullミドルウェアにより空文字はnullへ
        // 正規化される)が送られてきたら既存の暗号化値を維持し、値ありなら上書きする
        // (SystemSettingController::updateと同じ挙動)。
        foreach (self::SECRET_FIELDS as $secret) {
            if (array_key_exists($secret, $data) && $data[$secret] === null) {
                unset($data[$secret]);
            }
        }

        $connection = DB::transaction(function () use ($connection, $data, $request): ExternalIntegrationConnection {
            $before = $this->auditPayload($connection);
            $connection->update($data);
            $connection = $connection->fresh();
            $after = $this->auditPayload($connection);

            ExternalIntegrationConnectionAuditAggregate::retrieve($connection->id)
                ->recordUpdate($before, $after, $request->user()->id)
                ->persist();

            return $connection;
        });

        return response()->json($this->toResource($connection));
    }

    public function destroy(Request $request, string $externalIntegrationConnection): JsonResponse
    {
        $connection = ExternalIntegrationConnection::query()->findOrFail($externalIntegrationConnection);

        DB::transaction(function () use ($connection, $request): void {
            $before = $this->auditPayload($connection);
            ExternalIntegrationConnectionAuditAggregate::retrieve($connection->id)
                ->recordDelete($before, $request->user()->id)
                ->persist();
            $connection->delete();
        });

        return response()->json(null, 204);
    }

    /**
     * 監査イベントには機密値の生値を残さず、変更有無だけを残す。
     */
    private function auditPayload(ExternalIntegrationConnection $connection): array
    {
        $payload = $connection->only(['id', 'provider', 'name', 'auth_type', 'status', 'enabled', 'external_office_id', 'custom_settings']);
        foreach (self::SECRET_FIELDS as $secret) {
            $payload[$secret] = filled($connection->{$secret}) ? '[SET]' : null;
        }

        return $payload;
    }

    private function toResource(ExternalIntegrationConnection $connection): array
    {
        return [
            'id' => $connection->id,
            'provider' => $connection->provider,
            'name' => $connection->name,
            'auth_type' => $connection->auth_type,
            'status' => $connection->status,
            'enabled' => $connection->enabled,
            'external_office_id' => $connection->external_office_id,
            'custom_settings' => $connection->custom_settings,
            'has_client_id' => filled($connection->client_id),
            'has_client_secret' => filled($connection->client_secret),
            'has_api_key' => filled($connection->api_key),
            'client_id_masked' => $this->mask($connection->client_id),
            'api_key_masked' => $this->mask($connection->api_key),
            'connected_by_user_id' => $connection->connected_by_user_id,
            'connected_at' => $connection->connected_at?->toIso8601String(),
            'created_at' => $connection->created_at?->toIso8601String(),
            'updated_at' => $connection->updated_at?->toIso8601String(),
        ];
    }

    private function mask(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        return str_repeat('*', 4).substr($value, -4);
    }
}
