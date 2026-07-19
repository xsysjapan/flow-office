<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * メール通知(Microsoft Graph API sendMail)の設定をシステム全体設定に持たせる
 * (docs/13-usecases-notification.md UC-N001)。`notification_mail_enabled`が false、
 * または必須項目が未設定の場合はメール通知そのものを送らない(GraphMailNotifier参照)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->boolean('notification_mail_enabled')->default(false)->after('attendance_month_close_deadline_day');
            $table->string('notification_mail_tenant_id')->nullable()->after('notification_mail_enabled');
            $table->string('notification_mail_client_id')->nullable()->after('notification_mail_tenant_id');
            $table->text('notification_mail_client_secret')->nullable()->after('notification_mail_client_id');
            $table->string('notification_mail_sender_address')->nullable()->after('notification_mail_client_secret');
            $table->string('notification_mail_sender_name')->nullable()->after('notification_mail_sender_address');
        });
    }

    public function down(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->dropColumn([
                'notification_mail_enabled',
                'notification_mail_tenant_id',
                'notification_mail_client_id',
                'notification_mail_client_secret',
                'notification_mail_sender_address',
                'notification_mail_sender_name',
            ]);
        });
    }
};
