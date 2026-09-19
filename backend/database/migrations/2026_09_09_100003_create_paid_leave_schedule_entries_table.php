<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `App\Domain\PaidLeaveSchedule\Aggregates\PaidLeaveScheduleAggregate`のEntry状態を
 * 画面表示用に射影するProjection Table(CLAUDE.md原則2、再生成可能な派生データ)。
 * `App\Domain\PaidLeaveSchedule\Projectors\PaidLeaveScheduleEntryProjector`が
 * `stored_events`から更新する。列はspec.md論点12の一覧画面の列
 * (社員/付与予定日/区分/候補日数/出勤率/判定状態/変更・要確認)とAssessment内訳
 * (論点13の詳細パネル)に必要な値をそのまま持つ。
 *
 * 「変更・要確認」列は独立列を持たない。`status = NeedsReview`かつ
 * `manual_override_by_user_id`が入っている(=個別修正済みだったのに新しい算出結果と
 * 食い違ってNeedsReviewへ押し出された、spec.md論点7)という既存列の組み合わせだけで
 * 判定できるため、冗長な列を追加しない(CLAUDE.md原則2)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paid_leave_schedule_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users');
            $table->date('scheduled_on');
            $table->string('category', 32);
            $table->decimal('candidate_grant_days', 4, 1);
            $table->string('status', 32);

            $table->text('manual_override_reason')->nullable();
            $table->foreignUuid('manual_override_by_user_id')->nullable()->constrained('users');
            $table->timestamp('manual_override_at')->nullable();

            $table->date('assessment_period_start')->nullable();
            $table->date('assessment_period_end')->nullable();
            $table->unsignedSmallInteger('assessment_denominator_days')->nullable();
            $table->unsignedSmallInteger('assessment_attendance_days')->nullable();
            $table->unsignedSmallInteger('assessment_excluded_days')->nullable();
            $table->decimal('assessment_attendance_rate', 5, 2)->nullable();
            $table->string('assessment_policy_version', 32)->nullable();
            $table->string('assessment_automatic_result', 32)->nullable();
            $table->string('assessment_final_result', 32)->nullable();
            $table->text('assessment_override_reason')->nullable();

            $table->foreignUuid('granted_paid_leave_grant_id')->nullable()->constrained('paid_leave_grants');

            $table->timestamps();

            $table->index(['user_id', 'scheduled_on']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paid_leave_schedule_entries');
    }
};
