<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * workflow_requestsを全ドメイン横断の申請読み取りモデルに拡張する。
 *
 * subject_type/subject_idは、月次勤怠申請(attendance_months)・経費精算申請
 * (expense_claims)など、他ドメインの正データを指す任意のポリモーフィック参照
 * (entity_shares.shareable_type/shareable_idと同じ考え方)。従来通りの汎用申請
 * (request_types経由)は両方nullのままにする。
 *
 * request_type_idはsubject_type付きの行(月次勤怠・経費精算)には申請種別マスタが
 * 存在しないためnullable化する。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_requests', function (Blueprint $table) {
            $table->foreignId('request_type_id')->nullable()->change();

            $table->string('subject_type')->nullable()->after('request_type_id');
            // attendance_months.id / expense_claims.id はUUID文字列。entity_sharesと同じ理由で
            // 文字列カラムにする(将来int主キーの正データを対象にしても型を揃えられる)。
            $table->string('subject_id')->nullable()->after('subject_type');

            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::table('workflow_requests', function (Blueprint $table) {
            $table->dropIndex(['subject_type', 'subject_id']);
            $table->dropColumn(['subject_type', 'subject_id']);

            $table->foreignId('request_type_id')->nullable(false)->change();
        });
    }
};
