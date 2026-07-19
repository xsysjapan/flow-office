<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SSOログイン(UC-001)・MS365ユーザー同期(UC-002)・Graphメール送信(UC-N001)で共有する
 * Entra IDの資格情報を`system_settings`に1組へ統合する。従来メール送信専用に持っていた
 * notification_mail_tenant_id/client_id/client_secretは、この統合により重複となるため削除する。
 *
 * onboarding_completed_atは初回オンボーディング(docs/06-usecases-auth.md)が完了済みかを
 * 表す。nullの間はPOST /onboardingを未認証で受け付ける。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->string('m365_tenant_id')->nullable()->after('attendance_month_close_deadline_day');
            $table->string('m365_client_id')->nullable()->after('m365_tenant_id');
            $table->text('m365_client_secret')->nullable()->after('m365_client_id');
            $table->string('m365_redirect_uri')->nullable()->after('m365_client_secret');
            $table->boolean('m365_mock_enabled')->default(false)->after('m365_redirect_uri');
            $table->timestamp('onboarding_completed_at')->nullable()->after('m365_mock_enabled');

            $table->dropColumn([
                'notification_mail_tenant_id',
                'notification_mail_client_id',
                'notification_mail_client_secret',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->dropColumn([
                'm365_tenant_id',
                'm365_client_id',
                'm365_client_secret',
                'm365_redirect_uri',
                'm365_mock_enabled',
                'onboarding_completed_at',
            ]);

            $table->string('notification_mail_tenant_id')->nullable()->after('notification_mail_enabled');
            $table->string('notification_mail_client_id')->nullable()->after('notification_mail_tenant_id');
            $table->text('notification_mail_client_secret')->nullable()->after('notification_mail_client_id');
        });
    }
};
