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
    'notification_mail_enabled', 'notification_mail_tenant_id', 'notification_mail_client_id',
    'notification_mail_client_secret', 'notification_mail_sender_address', 'notification_mail_sender_name',
])]
class SystemSetting extends Model
{
    protected function casts(): array
    {
        return [
            'notification_mail_enabled' => 'boolean',
            // クライアントシークレットは平文でDBに保持しない (Laravelのencrypted castで暗号化する)。
            'notification_mail_client_secret' => 'encrypted',
        ];
    }

    /**
     * 常に存在する1行を返す。存在しない場合は既定値で作成する。
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate([], [
            'default_timezone' => 'Asia/Tokyo',
            'attendance_submission_deadline_day' => 5,
            'attendance_month_close_deadline_day' => 10,
        ]);
    }

    /**
     * メール通知を実際に送信できる状態か(有効化 + 必須項目が全て設定済み)。
     */
    public function notificationMailReady(): bool
    {
        return $this->notification_mail_enabled
            && (bool) $this->notification_mail_tenant_id
            && (bool) $this->notification_mail_client_id
            && (bool) $this->notification_mail_client_secret
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
