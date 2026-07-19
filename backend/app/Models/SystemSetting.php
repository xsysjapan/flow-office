<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * システム全体の設定 (docs/06-usecases-auth.md UC-003)。常に1行のみ存在する
 * シングルトンのマスタ。
 */
#[Fillable([
    'default_timezone', 'default_work_style_id', 'attendance_submission_deadline_day', 'attendance_month_close_deadline_day',
    'notification_mail_enabled', 'notification_mail_sender_address', 'notification_mail_sender_name',
    'm365_tenant_id', 'm365_client_id', 'm365_client_secret', 'm365_redirect_uri', 'm365_mock_enabled',
    'onboarding_completed_at',
])]
class SystemSetting extends Model
{
    protected function casts(): array
    {
        return [
            'notification_mail_enabled' => 'boolean',
            'm365_mock_enabled' => 'boolean',
            // クライアントシークレットは平文でDBに保持しない (Laravelのencrypted castで暗号化する)。
            'm365_client_secret' => 'encrypted',
            'onboarding_completed_at' => 'datetime',
        ];
    }

    /**
     * 常に存在する1行を返す。存在しない場合は既定値で作成する。
     *
     * m365_*・onboarding_completed_atの初期値は`.env`(services.azure.*、ローカル開発用
     * mock-oidc設定)からのフォールバックとする。本番環境では通常これらが未設定のため
     * onboarding_completed_atはnullのままとなり、初回オンボーディング(docs/06-usecases-auth.md)
     * が必須になる。devcontainer/docker-compose/CIのようにmock_enabledが`.env`で有効な
     * 環境は、初回`migrate --seed`だけでオンボーディング済み状態になる。
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate([], [
            'default_timezone' => 'Asia/Tokyo',
            'attendance_submission_deadline_day' => 5,
            'attendance_month_close_deadline_day' => 10,
            'm365_mock_enabled' => (bool) config('services.azure.mock_enabled'),
            'm365_tenant_id' => config('services.azure.tenant'),
            'm365_client_id' => config('services.azure.client_id'),
            'm365_client_secret' => config('services.azure.client_secret'),
            'm365_redirect_uri' => config('services.azure.redirect'),
            'onboarding_completed_at' => config('services.azure.mock_enabled') ? now() : null,
        ]);
    }

    /**
     * SSO・MS365同期・Graphメール送信で共有するEntra ID資格情報が全て設定済みか。
     */
    public function m365Configured(): bool
    {
        return (bool) $this->m365_tenant_id
            && (bool) $this->m365_client_id
            && (bool) $this->m365_client_secret;
    }

    /**
     * メール通知を実際に送信できる状態か(有効化 + 必須項目が全て設定済み)。
     */
    public function notificationMailReady(): bool
    {
        return $this->notification_mail_enabled
            && $this->m365Configured()
            && (bool) $this->notification_mail_sender_address;
    }

    /**
     * @return BelongsTo<WorkStyle, $this>
     */
    public function defaultWorkStyle(): BelongsTo
    {
        return $this->belongsTo(WorkStyle::class, 'default_work_style_id');
    }
}
