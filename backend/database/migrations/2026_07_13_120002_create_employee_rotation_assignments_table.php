<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 社員ごとのローテーション基準(指示書 8.5節)。1人につき現在有効な基準は1件のみとし
 * (user_idにunique制約)、切り替え時は上書きする。将来の班単位管理(指示書8.6節)を
 * 阻害しないよう、社員個別の基準として独立させておく。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_rotation_assignments', function (Blueprint $table) {
            // 集約ID(aggregate_id)としてstored_eventsに書き込まれるため、DB採番ではなく
            // コマンド側で生成できるUUIDにする(EmployeeRotationAssignmentProjector経由で行える
            // ようにするため。docs/29-event-sourcing-framework-migration.md参照)。
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained();
            $table->foreignUuid('rotation_pattern_id')->constrained();
            $table->date('rotation_start_date');
            $table->unsignedSmallInteger('rotation_start_position');
            $table->foreignUuid('assigned_by_user_id')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_rotation_assignments');
    }
};
