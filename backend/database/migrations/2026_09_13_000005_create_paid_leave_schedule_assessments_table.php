<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Schedule Entry 1件に対して複数回記録されうる出勤率Assessmentの版履歴Projection
     * (spec.md 論点2)。「誰が・いつ・何を根拠に・どう判定し・誰が上書きしたか」を
     * 監査可能な形で残す(依頼書§30・§40)。
     *
     * `2026_09_13_000004_create_paid_leave_schedule_entries_table.php`が退避した
     * `paid_leave_schedule_entries_legacy`(旧スキーマ、Assessment内訳を`assessment_*`列に
     * フラット化して持っていた)から、Assessmentが記録済み(`assessment_period_start`が
     * 非null)の行を1件ずつこのテーブルへ移行し、対応する新
     * `paid_leave_schedule_entries.latest_assessment_id`を補完する。移行が終わった時点で
     * `paid_leave_schedule_entries_legacy`は不要になるため削除する。
     */
    public function up(): void
    {
        Schema::create('paid_leave_schedule_assessments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('schedule_entry_id')->constrained('paid_leave_schedule_entries');
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedInteger('denominator_days');
            $table->unsignedInteger('attendance_days');
            $table->unsignedInteger('excluded_days')->default(0);
            $table->decimal('attendance_rate', 5, 2)->nullable();
            $table->string('policy_version');
            $table->string('automatic_result');
            $table->string('final_result');
            $table->string('override_reason')->nullable();
            $table->foreignUuid('overridden_by_user_id')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['schedule_entry_id']);
        });

        $legacyRowsWithAssessment = DB::table('paid_leave_schedule_entries_legacy')
            ->whereNotNull('assessment_period_start')
            ->orderBy('created_at')
            ->get();

        foreach ($legacyRowsWithAssessment as $legacyRow) {
            $assessmentId = (string) Str::uuid();

            DB::table('paid_leave_schedule_assessments')->insert([
                'id' => $assessmentId,
                'schedule_entry_id' => $legacyRow->id,
                'period_start' => $legacyRow->assessment_period_start,
                'period_end' => $legacyRow->assessment_period_end,
                'denominator_days' => $legacyRow->assessment_denominator_days ?? 0,
                'attendance_days' => $legacyRow->assessment_attendance_days ?? 0,
                'excluded_days' => $legacyRow->assessment_excluded_days ?? 0,
                'attendance_rate' => $legacyRow->assessment_attendance_rate,
                'policy_version' => $legacyRow->assessment_policy_version ?? 'unknown',
                'automatic_result' => $legacyRow->assessment_automatic_result ?? $legacyRow->status,
                'final_result' => $legacyRow->assessment_final_result ?? $legacyRow->assessment_automatic_result ?? $legacyRow->status,
                'override_reason' => $legacyRow->assessment_override_reason,
                'overridden_by_user_id' => null,
                'created_at' => $legacyRow->updated_at,
                'updated_at' => $legacyRow->updated_at,
            ]);

            DB::table('paid_leave_schedule_entries')
                ->where('id', $legacyRow->id)
                ->update(['latest_assessment_id' => $assessmentId]);
        }

        Schema::dropIfExists('paid_leave_schedule_entries_legacy');
    }

    public function down(): void
    {
        Schema::dropIfExists('paid_leave_schedule_assessments');
    }
};
