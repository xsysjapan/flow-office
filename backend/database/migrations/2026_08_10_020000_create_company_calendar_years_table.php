<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * company_calendars(本体)から年度依存カラム(fiscal_year/starts_on/ends_on/status/
     * published_at/published_by_user_id)を分離し、company_calendar_years(年度)へ移す
     * (docs/16-database-schema.md、UC-C009)。本番未使用のため単純なコピーでバックフィルする。
     */
    public function up(): void
    {
        Schema::create('company_calendar_years', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('company_calendar_id')->constrained('company_calendars')->cascadeOnDelete();
            $table->unsignedSmallInteger('fiscal_year');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status')->default('draft'); // draft, published, archived
            $table->string('generated_from')->default('manual'); // manual, standard_template
            $table->dateTime('published_at')->nullable();
            $table->foreignUuid('published_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_calendar_id', 'fiscal_year']);
        });

        $yearIdByCalendarId = [];

        foreach (DB::table('company_calendars')->get() as $calendar) {
            $yearId = (string) Str::uuid();
            $yearIdByCalendarId[$calendar->id] = $yearId;

            DB::table('company_calendar_years')->insert([
                'id' => $yearId,
                'company_calendar_id' => $calendar->id,
                'fiscal_year' => $calendar->fiscal_year,
                'starts_on' => $calendar->starts_on,
                'ends_on' => $calendar->ends_on,
                'status' => $calendar->status,
                'generated_from' => 'manual',
                'published_at' => null,
                'published_by_user_id' => null,
                'created_at' => $calendar->created_at,
                'updated_at' => $calendar->updated_at,
            ]);
        }

        // company_calendar_days.calendar_id の参照先を company_calendars.id から
        // company_calendar_years.id へ repoint する。この外部キーは本テーブルが
        // work_calendar_days という名前だった時代(2026_07_09_151954_a1)に作られたため、
        // MySQLでは制約名が今も work_calendar_days_calendar_id_foreign のままである
        // (テーブルのRENAMEは制約名まで追従しない)。dropForeign(['calendar_id'])は
        // 現在のテーブル名から規約で company_calendar_days_calendar_id_foreign を
        // 組み立ててしまい、MySQL上で「該当する制約が無い」エラーになるため、MySQLでは
        // 実際に存在する制約名を明示する。一方SQLiteは外部キーを名前でdropできず
        // (テーブル再作成方式のため列指定のみ対応)、列指定の形を使う必要がある。
        Schema::table('company_calendar_days', function (Blueprint $table) {
            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                $table->dropForeign(['calendar_id']);
            } else {
                $table->dropForeign('work_calendar_days_calendar_id_foreign');
            }
        });

        foreach ($yearIdByCalendarId as $calendarId => $yearId) {
            DB::table('company_calendar_days')->where('calendar_id', $calendarId)->update(['calendar_id' => $yearId]);
        }

        Schema::table('company_calendar_days', function (Blueprint $table) {
            $table->foreign('calendar_id')->references('id')->on('company_calendar_years')->cascadeOnDelete();
        });

        Schema::table('company_calendars', function (Blueprint $table) {
            $table->dropColumn(['fiscal_year', 'starts_on', 'ends_on', 'status']);
            $table->unsignedTinyInteger('fiscal_year_start_month')->default(4)->after('week_starts_on');
            $table->unsignedTinyInteger('fiscal_year_start_day')->default(1)->after('fiscal_year_start_month');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_calendars', function (Blueprint $table) {
            $table->dropColumn(['fiscal_year_start_month', 'fiscal_year_start_day']);
            $table->unsignedSmallInteger('fiscal_year')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->string('status')->default('draft');
        });

        foreach (DB::table('company_calendar_years')->get() as $year) {
            DB::table('company_calendars')->where('id', $year->company_calendar_id)->update([
                'fiscal_year' => $year->fiscal_year,
                'starts_on' => $year->starts_on,
                'ends_on' => $year->ends_on,
                'status' => $year->status,
            ]);

            DB::table('company_calendar_days')->where('calendar_id', $year->id)->update(['calendar_id' => $year->company_calendar_id]);
        }

        Schema::table('company_calendar_days', function (Blueprint $table) {
            $table->dropForeign(['calendar_id']);
        });

        Schema::table('company_calendar_days', function (Blueprint $table) {
            $table->foreign('calendar_id')->references('id')->on('company_calendars')->cascadeOnDelete();
        });

        Schema::dropIfExists('company_calendar_years');
    }
};
