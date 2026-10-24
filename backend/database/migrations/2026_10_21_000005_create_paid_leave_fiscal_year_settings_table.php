<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 既定の会社カレンダーの年度開始月日を、有給(付与予定)の文脈へ写した読み取りビュー(1行。id=1)。
 * `PaidLeaveFiscalYearSettingsProjector`がイベントから作る。有給の一斉付与日は年度開始日に連動する
 * (docs/changesets/20261021-paid-leave-fiscal-mass-grant-and-auto-assessment/spec.md 論点3)。
 *
 * 初期値は今の既定の会社カレンダーから入れる(既定のカレンダーが無ければ行を作らない。行が無いときは
 * 4月1日として扱う)。`last_changed_event_id`は、年度開始月日・既定のカレンダーが実際に変わったイベントのid
 * (作り直しのきっかけの判定用)で、初期値ではnull。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paid_leave_fiscal_year_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->uuid('company_calendar_id')->nullable();
            $table->unsignedTinyInteger('fiscal_year_start_month');
            $table->unsignedTinyInteger('fiscal_year_start_day');
            $table->unsignedBigInteger('last_changed_event_id')->nullable();
            $table->timestamps();
        });

        $default = DB::table('company_calendars')->where('is_default', true)->first();

        if ($default !== null) {
            DB::table('paid_leave_fiscal_year_settings')->insert([
                'id' => 1,
                'company_calendar_id' => $default->id,
                'fiscal_year_start_month' => $default->fiscal_year_start_month,
                'fiscal_year_start_day' => $default->fiscal_year_start_day,
                'last_changed_event_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('paid_leave_fiscal_year_settings');
    }
};
