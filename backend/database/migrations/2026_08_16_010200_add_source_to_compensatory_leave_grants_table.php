<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 代休Grantに手動付与(管理者による直接付与)を追加するため、由来を区別する
 * sourceカラムを追加する('attendance'=休日出勤実績からの自動導出、'manual'=管理者による
 * 手動付与)。既存行はすべて自動導出のため'attendance'で埋める。手動付与の付与理由を
 * 記録するgrant_reasonカラムも合わせて追加する(paid_leave_grants/special_leave_grantsに
 * 既にある同名カラムと同じ役割)。
 * 手動付与にはattendance_day_idが存在しないため、このカラムをnullable化する
 * (GrantCompensatoryLeaveHandler参照)。doctrine/dbalが未導入のため
 * Blueprint::change()は使わず、ドライバごとに素朴な方法でnullable化する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compensatory_leave_grants', function (Blueprint $table) {
            $table->string('source')->default('attendance')->after('user_id');
            $table->string('grant_reason')->nullable()->after('expires_on');
        });

        if (DB::getDriverName() === 'sqlite') {
            $this->makeAttendanceDayIdNullableOnSqlite();

            return;
        }

        DB::statement('ALTER TABLE compensatory_leave_grants MODIFY attendance_day_id CHAR(36) NULL');
    }

    /**
     * SQLiteはNOT NULL制約を後から緩められないため(doctrine/dbal未導入でBlueprint::change()も
     * 使えない)、テーブルを作り直して既存データを移し替える。
     * `compensatory_leave_usages.compensatory_leave_grant_id`等、他テーブルの外部キーは
     * テーブル名「compensatory_leave_grants」を文字列として参照しているため、
     * 旧テーブルをリネームする方式(rename→create→drop old)は使わない
     * (SQLiteのlegacy_alter_table設定次第でリネーム時に他テーブルのFK定義が
     * 追随して書き換わり、リネーム後の名前を指したまま残ってしまうことがあるため)。
     * 代わりに新テーブルを別名で作成し、データ移行後に「元のテーブルを削除」→
     * 「新テーブルを元の名前へリネーム」の順で行うことで、他テーブルのFK定義に現れる
     * テーブル名を一切変更しないようにする。
     */
    private function makeAttendanceDayIdNullableOnSqlite(): void
    {
        Schema::create('compensatory_leave_grants_new', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('source')->default('attendance');
            $table->foreignUuid('user_id')->constrained();
            $table->uuid('attendance_day_id')->nullable()->unique('compensatory_leave_grants_new_attendance_day_id_unique');
            $table->date('work_date');
            $table->decimal('granted_days', 4, 1)->default(0);
            $table->unsignedInteger('granted_minutes')->nullable();
            $table->decimal('used_days', 4, 1)->default(0);
            $table->unsignedInteger('used_minutes')->nullable();
            $table->decimal('remaining_days', 4, 1)->default(0);
            $table->unsignedInteger('remaining_minutes')->nullable();
            $table->string('status')->default('draft');
            $table->timestamp('confirmed_at')->nullable();
            $table->date('expires_on')->nullable();
            $table->string('grant_reason')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status', 'expires_on'], 'compensatory_leave_grants_new_user_status_expires_index');
        });

        DB::statement('
            INSERT INTO compensatory_leave_grants_new (
                id, source, user_id, attendance_day_id, work_date, granted_days, granted_minutes,
                used_days, used_minutes, remaining_days, remaining_minutes, status, confirmed_at,
                expires_on, grant_reason, created_at, updated_at
            )
            SELECT
                id, source, user_id, attendance_day_id, work_date, granted_days, granted_minutes,
                used_days, used_minutes, remaining_days, remaining_minutes, status, confirmed_at,
                expires_on, grant_reason, created_at, updated_at
            FROM compensatory_leave_grants
        ');

        Schema::drop('compensatory_leave_grants');
        Schema::rename('compensatory_leave_grants_new', 'compensatory_leave_grants');

        // リネーム後、インデックス名を最終的な命名(compensatory_leave_grants_*)に揃える。
        DB::statement('DROP INDEX IF EXISTS compensatory_leave_grants_new_user_status_expires_index');
        DB::statement('DROP INDEX IF EXISTS compensatory_leave_grants_new_attendance_day_id_unique');
        DB::statement('CREATE UNIQUE INDEX compensatory_leave_grants_attendance_day_id_unique ON compensatory_leave_grants (attendance_day_id)');
        DB::statement('CREATE INDEX compensatory_leave_grants_user_status_expires_index ON compensatory_leave_grants (user_id, status, expires_on)');
    }

    public function down(): void
    {
        Schema::table('compensatory_leave_grants', function (Blueprint $table) {
            $table->dropColumn(['source', 'grant_reason']);
        });
    }
};
