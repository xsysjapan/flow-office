<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 付与予定の行を(付与予定日, 名目の継続勤務月数)で見分けるため、名目の継続勤務月数を持たせる。
 * 古いイベントには名目の月数が無いのでnullを許す。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paid_leave_schedule_entries', function (Blueprint $table) {
            $table->unsignedSmallInteger('nominal_service_months')->nullable()->after('candidate_grant_days');
        });
    }

    public function down(): void
    {
        Schema::table('paid_leave_schedule_entries', function (Blueprint $table) {
            $table->dropColumn('nominal_service_months');
        });
    }
};
