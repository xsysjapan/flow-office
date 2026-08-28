<?php

namespace App\Domain\Export\Services;

use App\Domain\Export\Contracts\AuthStrategy;
use App\Domain\Export\Contracts\ExternalPublisher;
use App\Domain\Export\Services\AttendanceApi\AttendanceApiPayloadBuilder;
use App\Domain\Export\Services\AttendanceApi\FreeeAttendanceApiPayloadBuilder;
use App\Domain\Export\Services\ExpenseApi\ExpenseApiPayloadBuilder;
use App\Domain\Export\Services\ExpenseApi\FreeeExpenseApiPayloadBuilder;
use App\Domain\Export\Services\ExpenseApi\MoneyForwardExpenseApiPayloadBuilder;
use App\Domain\Export\Services\ExternalAuth\ApiKeyStrategy;
use App\Domain\Export\Services\ExternalAuth\OAuth2Strategy;
use App\Domain\Export\Services\Publishers\ExternalApiPublisher;
use App\Domain\Export\Services\Publishers\MoneyForwardExpensePublisher;
use App\Models\ExternalIntegrationConnection;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * providerキー('freee'/'moneyforward')から、認可済みのExternalPublisherと
 * AttendanceApiPayloadBuilder/ExpenseApiPayloadBuilderを組み立てる。config/external_integrations.php
 * のエンドポイント設定とexternal_integration_connectionsの認可情報を紐付ける役割
 * (AttendanceCsvFormatのresolveAttendanceCsvFormatと同じ発想)。
 *
 * 勤怠のAPIプッシュ連携はfreeeのみ対応する(MoneyForwardクラウド勤怠/給与には外部から
 * 勤怠データをプッシュする公開APIが存在しないため。docs/notes/moneyforward-api-investigation.md)。
 * MoneyForward向け勤怠出力は引き続きCSVのみで案内する。
 * docs/33-usecases-attendance-external-api.md, docs/30-usecases-expense.md参照。
 */
class ExternalIntegrationPublisherResolver
{
    private const ATTENDANCE_PROVIDERS = [
        ExternalIntegrationConnection::PROVIDER_FREEE,
    ];

    private const EXPENSE_PROVIDERS = [
        ExternalIntegrationConnection::PROVIDER_FREEE,
        ExternalIntegrationConnection::PROVIDER_MONEYFORWARD,
    ];

    /**
     * @return array{0: ExternalPublisher, 1: AttendanceApiPayloadBuilder, 2: ?string}
     */
    public function resolve(string $provider): array
    {
        if (! in_array($provider, self::ATTENDANCE_PROVIDERS, true)) {
            throw ValidationException::withMessages(['provider' => ['サポートされていない連携先です。']]);
        }

        $connection = $this->resolveActiveConnection($provider);
        $authStrategy = $this->resolveAuthStrategy($connection);
        $endpoint = $this->requireEndpoint($provider, 'api_endpoint');

        // freee人事労務の勤怠サマリー更新API(work_record_summaries)はPUT
        // (「データが無ければ新規作成、あれば上書き」。docs/notes/moneyforward-api-investigation.md)。
        $publisher = new ExternalApiPublisher($provider, $authStrategy, $endpoint, 'PUT');
        $builder = $this->resolvePayloadBuilder($provider);

        return [$publisher, $builder, $connection->external_office_id];
    }

    /**
     * フェーズ3: 経費API連携用。認可基盤(AuthStrategy/ExternalIntegrationConnection)は
     * resolve()と共通だが、MoneyForwardは仕訳ではなく経費明細(ex_transaction)を
     * 1件ずつ作成する2段階呼び出し(ex_transactions + upload_receipt)のため、
     * 通常のExternalApiPublisherではなくMoneyForwardExpensePublisherを使う。
     * docs/30-usecases-expense.md UC-X012参照。
     *
     * @return array{0: ExternalPublisher, 1: ExpenseApiPayloadBuilder}
     */
    public function resolveExpense(string $provider): array
    {
        if (! in_array($provider, self::EXPENSE_PROVIDERS, true)) {
            throw ValidationException::withMessages(['provider' => ['サポートされていない連携先です。']]);
        }

        $connection = $this->resolveActiveConnection($provider);
        $authStrategy = $this->resolveAuthStrategy($connection);
        $builder = $this->resolveExpensePayloadBuilder($provider);

        if ($provider === ExternalIntegrationConnection::PROVIDER_MONEYFORWARD) {
            $publisher = new MoneyForwardExpensePublisher(
                $provider,
                $authStrategy,
                $connection->requireExternalOfficeId(),
                $this->requireEndpoint($provider, 'expense_api_endpoint'),
                $this->requireEndpoint($provider, 'expense_receipt_upload_endpoint'),
            );

            return [$publisher, $builder];
        }

        $publisher = new ExternalApiPublisher($provider, $authStrategy, $this->requireEndpoint($provider, 'expense_api_endpoint'));

        return [$publisher, $builder];
    }

    private function resolveActiveConnection(string $provider): ExternalIntegrationConnection
    {
        // 同一providerで複数登録できるようになったため、有効化(enabled)されている行のみを対象にする。
        // 複数登録時は最初に見つかった有効な1件を使う。特定の1件を選ばせるUIは今回のスコープ外。
        $connection = ExternalIntegrationConnection::query()
            ->where('provider', $provider)
            ->where('enabled', true)
            ->where('status', ExternalIntegrationConnection::STATUS_ACTIVE)
            ->first();

        if ($connection === null) {
            throw new RuntimeException("{$provider}との連携が未設定です。先に連携認可(接続)を行ってください。");
        }

        return $connection;
    }

    private function requireEndpoint(string $provider, string $endpointConfigKey): string
    {
        $endpoint = config("external_integrations.{$provider}.{$endpointConfigKey}");
        if (! is_string($endpoint) || $endpoint === '') {
            throw new RuntimeException("{$provider}のAPIエンドポイント設定がありません。");
        }

        return $endpoint;
    }

    private function resolveAuthStrategy(ExternalIntegrationConnection $connection): AuthStrategy
    {
        return match ($connection->auth_type) {
            ExternalIntegrationConnection::AUTH_TYPE_OAUTH2 => new OAuth2Strategy(
                $connection,
                (string) config("external_integrations.{$connection->provider}.token_endpoint"),
            ),
            ExternalIntegrationConnection::AUTH_TYPE_API_KEY => new ApiKeyStrategy($connection),
            default => throw new RuntimeException("未対応の認可方式です: {$connection->auth_type}"),
        };
    }

    private function resolvePayloadBuilder(string $provider): AttendanceApiPayloadBuilder
    {
        return match ($provider) {
            ExternalIntegrationConnection::PROVIDER_FREEE => new FreeeAttendanceApiPayloadBuilder,
            default => throw new RuntimeException("未対応の連携先です: {$provider}"),
        };
    }

    private function resolveExpensePayloadBuilder(string $provider): ExpenseApiPayloadBuilder
    {
        return match ($provider) {
            ExternalIntegrationConnection::PROVIDER_FREEE => new FreeeExpenseApiPayloadBuilder,
            ExternalIntegrationConnection::PROVIDER_MONEYFORWARD => new MoneyForwardExpenseApiPayloadBuilder,
            default => throw new RuntimeException("未対応の連携先です: {$provider}"),
        };
    }
}
