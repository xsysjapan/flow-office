<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UC-P002: 有給を自動付与する。継続勤務期間の計算に入社日が必要なため追加する。
 * MS365には入社日に対応する属性がないため同期対象外とし、管理者が個別に設定する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->date('hire_date')->nullable()->after('employment_status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('hire_date');
        });
    }
};
