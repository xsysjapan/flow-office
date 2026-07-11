<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * work_time_system は「シフト制かどうか」を表す値ではない(is_shift_basedと重複していた)。
 * `shift_based` は fixed + is_shift_based=true に、`shortened` は所定労働時間が短いだけの
 * fixed に統合する。
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('work_styles')->where('work_time_system', 'shift_based')->update([
            'work_time_system' => 'fixed',
            'is_shift_based' => true,
        ]);

        DB::table('work_styles')->where('work_time_system', 'shortened')->update([
            'work_time_system' => 'fixed',
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // データ変換のみのため、どの行がもともとshift_based/shortenedだったかは復元できない。
    }
};
