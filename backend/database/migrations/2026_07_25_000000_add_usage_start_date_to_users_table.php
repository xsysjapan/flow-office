<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 利用開始日: このシステムの利用を開始した日。勤怠提出フォロー等の各種フォロー通知は
 * 利用開始日(および入社日)より前の期間について送らないようにするための基準日として使う。
 * MS365には対応する属性がないため同期対象外とし、管理者が個別に設定する(hire_dateと同様)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->date('usage_start_date')->nullable()->after('hire_date');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('usage_start_date');
        });
    }
};
