<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 通常付与の法定日数表(労働基準法39条)。docs/changesets/
 * 20260906-paid-leave-schedule-assessment/spec.md 論点4。`version`で版管理し、
 * 将来法改正があっても過去に確定したAssessment・Grantの根拠(policyVersion)を
 * 変えずに新版を追加できるようにする。現在有効な版は「最大version」とする
 * (`App\Models\PaidLeaveGrantPolicy::currentVersion()`)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paid_leave_grant_policies', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('version');
            $table->unsignedSmallInteger('continuous_service_months');
            $table->decimal('grant_days', 4, 1);
            $table->timestamps();

            $table->unique(['version', 'continuous_service_months'], 'paid_leave_grant_policies_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paid_leave_grant_policies');
    }
};
