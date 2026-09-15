<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 通常付与(労働基準法第39条第1項・第2項)の法定付与日数マスタ。
     * `continuous_service_months`(継続勤務月数)ごとに`grant_days`(付与日数)を持つ。
     * `version`で世代管理し、将来法改正があっても過去のAssessment/Grantの根拠を
     * 変えずに新版を追加できるようにする(CLAUDE.md原則8、spec.md 論点4)。
     */
    public function up(): void
    {
        Schema::create('paid_leave_grant_policies', function (Blueprint $table) {
            $table->id();
            $table->string('version');
            $table->unsignedInteger('continuous_service_months');
            $table->decimal('grant_days', 4, 1);
            $table->date('effective_from')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['version', 'continuous_service_months']);
            $table->index(['is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paid_leave_grant_policies');
    }
};
