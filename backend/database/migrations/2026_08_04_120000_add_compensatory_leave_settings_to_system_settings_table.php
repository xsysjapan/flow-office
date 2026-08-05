<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 代休制度(休日出勤の実績から自動導出するGrant、消化は申請制)のシステム全体設定。
 * Grantの生成・確定は申請を経由しないため、承認要否設定(requires_approval)は消化申請にのみ
 * 適用される。付与・消化の単位(daily/half_day/hourly)によって、half_day選択時のみ
 * half_day_threshold_minutesが意味を持つ(超えれば1.0日、以下なら0.5日)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->boolean('compensatory_leave_enabled')->default(false)->after('shift_swap_requires_approval');
            $table->boolean('compensatory_leave_requires_approval')->default(true)->after('compensatory_leave_enabled');
            $table->string('compensatory_leave_unit')->default('daily')->after('compensatory_leave_requires_approval');
            $table->integer('compensatory_leave_half_day_threshold_minutes')->nullable()->after('compensatory_leave_unit');
            $table->integer('compensatory_leave_valid_days')->nullable()->after('compensatory_leave_half_day_threshold_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('system_settings', function (Blueprint $table) {
            $table->dropColumn([
                'compensatory_leave_enabled',
                'compensatory_leave_requires_approval',
                'compensatory_leave_unit',
                'compensatory_leave_half_day_threshold_minutes',
                'compensatory_leave_valid_days',
            ]);
        });
    }
};
