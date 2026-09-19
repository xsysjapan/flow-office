<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `2026_09_09_100003_create_paid_leave_schedule_entries_table.php`が作った旧スキーマ
     * (出勤率Assessmentの内訳を`assessment_*`列としてこのテーブルへ直接フラット化して
     * 持つ設計)を、Assessmentを独立したProjection Table
     * (`paid_leave_schedule_assessments`、次のマイグレーションで作成)に切り出す新設計へ
     * 移行する。
     *
     * Assessment内訳データの移行(`paid_leave_schedule_assessments`へのINSERTと、この
     * テーブルの`latest_assessment_id`の補完)は、そのテーブルがまだ存在しないためここでは
     * 行えない。旧テーブル(`_legacy`)は次の
     * `2026_09_13_000005_create_paid_leave_schedule_assessments_table.php`側で読み取って
     * から削除するので、このマイグレーションでは削除しない。
     */
    public function up(): void
    {
        Schema::rename('paid_leave_schedule_entries', 'paid_leave_schedule_entries_legacy');

        Schema::create('paid_leave_schedule_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // MySQLは外部キー制約名がスキーマ全体で一意である必要があり、`Schema::rename()`後も
            // 旧テーブル(`_legacy`)側の制約名(`paid_leave_schedule_entries_user_id_foreign`等)は
            // そのまま残る。自動生成名だと衝突するため、明示的に別名を指定する
            // (`constrained()`の第3引数)。
            $table->foreignUuid('user_id')->constrained('users', 'id', 'paid_leave_schedule_entries_user_id_foreign_v2');
            $table->date('scheduled_on');
            $table->string('category');
            $table->decimal('candidate_grant_days', 4, 1);
            $table->string('status');
            $table->uuid('latest_assessment_id')->nullable();
            $table->boolean('is_manually_overridden')->default(false);
            $table->string('manual_override_reason')->nullable();
            $table->foreignUuid('manual_override_by_user_id')->nullable()
                ->constrained('users', 'id', 'pl_schedule_entries_override_by_user_id_foreign_v2');
            $table->timestamp('manual_override_at')->nullable();
            $table->uuid('grant_id')->nullable();
            $table->string('cancelled_reason')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['scheduled_on', 'status']);
        });

        foreach (DB::table('paid_leave_schedule_entries_legacy')->orderBy('created_at')->get() as $legacyRow) {
            DB::table('paid_leave_schedule_entries')->insert([
                'id' => $legacyRow->id,
                'user_id' => $legacyRow->user_id,
                'scheduled_on' => $legacyRow->scheduled_on,
                'category' => $legacyRow->category,
                'candidate_grant_days' => $legacyRow->candidate_grant_days,
                'status' => $legacyRow->status,
                'latest_assessment_id' => null,
                'is_manually_overridden' => $legacyRow->manual_override_by_user_id !== null,
                'manual_override_reason' => $legacyRow->manual_override_reason !== null
                    ? mb_substr($legacyRow->manual_override_reason, 0, 255)
                    : null,
                'manual_override_by_user_id' => $legacyRow->manual_override_by_user_id,
                'manual_override_at' => $legacyRow->manual_override_at,
                'grant_id' => $legacyRow->granted_paid_leave_grant_id,
                'cancelled_reason' => null,
                'created_at' => $legacyRow->created_at,
                'updated_at' => $legacyRow->updated_at,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('paid_leave_schedule_entries');
    }
};
