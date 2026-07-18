<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * attendance_punches.integration_id は application_integrations 作成前に列だけ
 * 追加していたため(2026_07_18_090006)、ここで実際の外部キー制約を付与する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->foreign('integration_id')->references('id')->on('application_integrations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->dropForeign(['integration_id']);
        });
    }
};
