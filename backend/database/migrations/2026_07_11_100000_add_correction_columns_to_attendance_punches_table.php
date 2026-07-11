<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 打刻ログの訂正・削除 (docs/07-usecases-attendance.md UC-A013/UC-A014)。
 *
 * 打刻ログは追記のみで、既存の行を直接書き換えない。訂正は新しい打刻行を作成し、
 * 元の行を `corrected` としてそちらを指すようにする。削除は行を物理削除せず `deleted` に
 * するだけで、どちらも理由・実行者・日時を保持したまま参照できる。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->string('status')->default('active')->after('note'); // active, corrected, deleted
            $table->text('correction_reason')->nullable()->after('status');
            $table->foreignId('corrected_by_user_id')->nullable()->after('correction_reason')->constrained('users');
            $table->timestamp('corrected_at')->nullable()->after('corrected_by_user_id');
            $table->foreignId('superseded_by_punch_id')->nullable()->after('corrected_at')
                ->constrained('attendance_punches');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('superseded_by_punch_id');
            $table->dropConstrainedForeignId('corrected_by_user_id');
            $table->dropColumn(['status', 'correction_reason', 'corrected_at']);
        });
    }
};
