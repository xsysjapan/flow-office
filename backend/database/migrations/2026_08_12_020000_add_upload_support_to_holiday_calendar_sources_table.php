<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * 祝日iCalendarソースにアップロード方式(source_kind = 'upload')を追加する。
     * ics_urlはsource_kind='upload'では使わないためnullable化する。
     */
    public function up(): void
    {
        Schema::table('holiday_calendar_sources', function (Blueprint $table) {
            $table->string('source_kind')->default('url')->after('name'); // url, upload
            $table->string('uploaded_ics_path')->nullable()->after('ics_url');
            $table->string('uploaded_ics_filename')->nullable()->after('uploaded_ics_path');
        });

        Schema::table('holiday_calendar_sources', function (Blueprint $table) {
            $table->string('ics_url')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('holiday_calendar_sources', function (Blueprint $table) {
            $table->dropColumn(['source_kind', 'uploaded_ics_path', 'uploaded_ics_filename']);
        });

        Schema::table('holiday_calendar_sources', function (Blueprint $table) {
            $table->string('ics_url')->nullable(false)->change();
        });
    }
};
