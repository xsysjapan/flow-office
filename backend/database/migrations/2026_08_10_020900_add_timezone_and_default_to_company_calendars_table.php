<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * docs/16-database-schema.md の company_calendars(本体) 定義にある timezone・
     * is_default・status(本体としての有効/廃止。年度のstatusとは別軸)を追加する。
     * is_default は組織内に常に高々1件のみtrueであることを前提にし、既存の全本体に
     * デフォルト値をセットした後、最初の1件をtrueにする(本番未使用のため単純な移行でよい)。
     */
    public function up(): void
    {
        Schema::table('company_calendars', function (Blueprint $table) {
            $table->string('timezone')->default('Asia/Tokyo')->after('week_starts_on');
            $table->boolean('is_default')->default(false)->after('fiscal_year_start_day');
            $table->string('status')->default('active')->after('is_default'); // active, archived
        });

        $firstId = DB::table('company_calendars')->orderBy('created_at')->value('id');
        if ($firstId !== null) {
            DB::table('company_calendars')->where('id', $firstId)->update(['is_default' => true]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_calendars', function (Blueprint $table) {
            $table->dropColumn(['timezone', 'is_default', 'status']);
        });
    }
};
