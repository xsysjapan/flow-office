<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 有給付与の時効(労働基準法115条、既定2年)。spec.md 論点4。`version`で版管理し、
 * 現在有効な版は「最大version」とする。`expiry_years`はCarbon::addYears()呼び出しに
 * そのまま使える整数の年数として保持する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paid_leave_grant_expiry_policy', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('version')->unique();
            $table->unsignedTinyInteger('expiry_years');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paid_leave_grant_expiry_policy');
    }
};
