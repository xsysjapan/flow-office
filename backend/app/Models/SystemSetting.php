<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

/**
 * システム全体の設定 (docs/06-usecases-auth.md UC-003)。常に1行のみ存在する
 * シングルトンのマスタ。
 */
#[Fillable([
    'default_timezone', 'default_work_style_id', 'attendance_submission_deadline_day', 'attendance_month_close_deadline_day',
    'notification_mail_enabled', 'notification_mail_sender_address', 'notification_mail_sender_name',
    'm365_tenant_id', 'm365_client_id', 'm365_client_secret', 'm365_redirect_uri', 'm365_mock_enabled',
    'onboarding_started_at', 'onboarding_completed_at',
])]
class SystemSetting extends Model
{
    /** サービスコンテナへバインドするキー。リクエストごと(=コンテナごと)に1回だけ問い合わせる
     *  ためのキャッシュに使う(テスト実行時もLaravelのテストハーネストがリクエストごとに
     *  コンテナを作り直すため、素のstaticプロパティと違いテスト間で汚染されない)。 */
    private const CACHE_BINDING = 'system_settings.current';

    protected function casts(): array
    {
        return [
            'notification_mail_enabled' => 'boolean',
            'm365_mock_enabled' => 'boolean',
            // クライアントシークレットは平文でDBに保持しない (Laravelのencrypted castで暗号化する)。
            'm365_client_secret' => 'encrypted',
            'onboarding_started_at' => 'datetime',
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
        if (app()->bound(self::CACHE_BINDING)) {
            return app(self::CACHE_BINDING);
        }

        $settings = static::query()->firstOrCreate([], [
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

        // system_settingsは内部的なシングルトン設定であり、REST的な「作成された
        // リソース」ではない。firstOrCreate()が初回にこの行を作成した場合、
        // wasRecentlyCreatedがtrueのままだと、これをリクエスト内で使い回すキャッシュの
        // 性質上、後続の全く別の操作(例:PUT /system-settingsの更新レスポンス)まで
        // JsonResourceの自動201判定に巻き込んでしまうため、ここで明示的にfalseへ戻す。
        $settings->wasRecentlyCreated = false;

        app()->instance(self::CACHE_BINDING, $settings);

        return $settings;
    }

    /**
     * 初回オンボーディング(UC-000)の開始を原子的にクレームする。`$attributes`で
     * system_settingsを更新しつつ、既に開始・完了済みなら何も更新せずfalseを返す
     * (読んでから書く二段階処理によるレース条件を避けるため、単一のUPDATE文で
     * 条件判定と書き込みを同時に行う)。開始から10分経っても完了していない場合は
     * 再クレームを許可する(SSOログインを最後までやり切らずに離脱した場合の
     * 永久ロック防止、`AuthController`の交換コードTTLと同じ考え方)。
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function claimOnboarding(array $attributes): bool
    {
        self::current(); // 行が存在することを保証する

        $claimed = static::query()
            ->whereNull('onboarding_completed_at')
            ->where(function ($query) {
                $query->whereNull('onboarding_started_at')
                    ->orWhere('onboarding_started_at', '<', now()->subMinutes(10));
            })
            ->update(self::encryptCastAttributes($attributes));

        if ($claimed > 0 && app()->bound(self::CACHE_BINDING)) {
            app()->forgetInstance(self::CACHE_BINDING);
        }

        return $claimed > 0;
    }

    /**
     * 初回オンボーディング(UC-000)を原子的に完了させる。`onboarding_completed_at`が
     * 未設定の場合のみ`$attributes`(空でもよい)と合わせて完了扱いにする。ローカル
     * パスワードモードは`claimOnboarding()`を経由せずこのメソッドだけで一度に完了させる
     * (1リクエストで完結するため)。SSOモードは`claimOnboarding()`で開始した後、
     * 実際のEntra IDログインが成功した時点でこのメソッドを呼ぶ。
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function completeOnboarding(array $attributes = []): bool
    {
        $completed = static::query()
            ->whereNull('onboarding_completed_at')
            ->update(self::encryptCastAttributes([...$attributes, 'onboarding_completed_at' => now()]));

        if ($completed > 0 && app()->bound(self::CACHE_BINDING)) {
            app()->forgetInstance(self::CACHE_BINDING);
        }

        return $completed > 0;
    }

    /**
     * クエリビルダ経由の生UPDATE(`claimOnboarding()`/`completeOnboarding()`)は
     * Eloquentのキャスト(mutator)を経由しないため、`encrypted`キャストの列は自前で
     * 暗号化してから書き込む必要がある。`$model->save()`が内部で使うのと同じ
     * 暗号化(`Crypt::encryptString()` = `encrypted`キャストの実体)を使う。
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private static function encryptCastAttributes(array $attributes): array
    {
        $instance = new static;

        foreach ($attributes as $key => $value) {
            if ($value !== null && ($instance->getCasts()[$key] ?? null) === 'encrypted') {
                $attributes[$key] = Crypt::encryptString($value);
            }
        }

        return $attributes;
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
