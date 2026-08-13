<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('company_calendars', function (Blueprint $table) {
            // 既定はtrue(許容)。就業規則に法定休日の例外が無く曜日指定のみの場合、
            // 管理画面からfalseに変更して日別編集をロックする(業務上の意図。移行前の
            // 挙動 = 技術的に日別編集を止めるものが何も無かった状態と同じ)。
            $table->boolean('allow_daily_holiday_override')->default(true)->after('weekday_holiday_pattern');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_calendars', function (Blueprint $table) {
            $table->dropColumn('allow_daily_holiday_override');
        });
    }
};
