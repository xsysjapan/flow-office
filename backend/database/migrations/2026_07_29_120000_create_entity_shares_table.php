<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 複数ドメイン(月次勤怠申請・経費精算申請など)から共通で使う「共有」記録。
 * 申請提出時に元エンティティ(勤怠日次データ・経費明細等)を承認者へ開示したことを表す
 * 追記専用ログであり、削除・更新は行わない(ルートCLAUDE.md「絶対に外してはいけない設計原則」)。
 *
 * このテーブル自体は独立したドメインイベントを持たない単純な記録テーブル。
 * 各ドメイン(Attendance/ExpenseClaim)のSubmitハンドラが発行する「共有」イベントの
 * Projector/反映処理から`EntityShare::query()->create([...])`で追記される想定
 * (配線は別タスク)。
 *
 * shareable_type/shareable_id は Attachment(owner_type/owner_id)・BackOfficeTask
 * (source_type/source_id)と同じ考え方のポリモーフィックカラム。対象の主キーが
 * UUID(attendance_months.id, expense_claims.id)とint混在のため文字列にする。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_shares', function (Blueprint $table) {
            $table->id();
            $table->string('shareable_type');
            $table->string('shareable_id');
            $table->foreignUuid('shared_with_user_id')->constrained('users');
            $table->foreignUuid('shared_by_user_id')->constrained('users');
            $table->timestamp('shared_at');
            // updated_atは不要(追記専用)。created_atのみ手動定義しモデル側でUPDATED_AT=nullにする。
            $table->timestamp('created_at')->nullable();

            $table->index(['shareable_type', 'shareable_id', 'shared_with_user_id'], 'entity_shares_shareable_shared_with_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_shares');
    }
};
