<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 特別休暇種別ごとに、事前の付与(残数)なしで申請できるかどうかを設定できるようにする
 * (例: 忌引・代休のように、会社の制度上あらかじめ残数を付与しない種別向け)。
 * true(既定)の種別は従来通り残数不足なら申請できない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('special_leave_types', function (Blueprint $table) {
            $table->boolean('requires_grant')->default(true)->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('special_leave_types', function (Blueprint $table) {
            $table->dropColumn('requires_grant');
        });
    }
};
