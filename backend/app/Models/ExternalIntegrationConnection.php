<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 勤怠API連携(フェーズ2)の認可情報。docs/33-usecases-attendance-external-api.md参照。
 * トークン・APIキー系カラムはencryptedキャストで暗号化して保存する(平文で保持しない)。
 */
#[Fillable(['id', 'provider', 'name', 'external_office_id', 'auth_type', 'status', 'enabled', 'access_token', 'refresh_token', 'api_key', 'client_id', 'client_secret', 'custom_settings', 'token_expires_at', 'connected_by_user_id', 'connected_at'])]
class ExternalIntegrationConnection extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    public const PROVIDER_FREEE = 'freee';

    public const PROVIDER_MONEYFORWARD = 'moneyforward';

    public const AUTH_TYPE_OAUTH2 = 'oauth2';

    public const AUTH_TYPE_API_KEY = 'api_key';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISCONNECTED = 'disconnected';

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'api_key' => 'encrypted',
            'client_id' => 'encrypted',
            'client_secret' => 'encrypted',
            'custom_settings' => 'array',
            'enabled' => 'boolean',
            'token_expires_at' => 'datetime',
            'connected_at' => 'datetime',
        ];
    }

    /**
     * MoneyForwardクラウド経費API(ex_transactions)のURL構築に使うオフィスID。
     * office_member_id側はexternal_employee_mappings.external_employee_codeを流用する
     * (docs/notes/moneyforward-api-investigation.md)。
     */
    public function requireExternalOfficeId(): string
    {
        if (blank($this->external_office_id)) {
            throw new \RuntimeException("{$this->provider}のオフィスID(external_office_id)が未設定です。連携設定を確認してください。");
        }

        return $this->external_office_id;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by_user_id');
    }
}
