<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ユーザーにその月の働き方(user_work_style_monthly_assignments)が設定されていない場合に
 * 使うデフォルトの勤務形態を、システム全体設定の1項目として持たせる。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->foreignUuid('default_work_style_id')->nullable()->after('default_timezone')
                ->constrained('work_styles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_work_style_id');
        });
    }
};
