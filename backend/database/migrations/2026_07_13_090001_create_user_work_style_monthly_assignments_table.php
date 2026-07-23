<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ユーザーが月単位でどの働き方(work_styles)に属するかの正データ。
 * 例えば10月までは通常勤務、11月からシフト勤務のように、月ごとの切り替えを
 * 過去分を壊さずに履歴として残すために持つ(docs/08-usecases-calendar-shift.md参照)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_work_style_monthly_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id')->constrained();
            $table->string('year_month', 7); // 'YYYY-MM'
            $table->foreignId('work_style_id')->constrained();
            $table->foreignUuid('assigned_by_user_id')->constrained('users');
            $table->timestamps();

            $table->unique(['user_id', 'year_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_work_style_monthly_assignments');
    }
};
