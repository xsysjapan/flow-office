<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 「申請センター」画面向けの横断Projection。paid_leave_requests / compensatory_leave_requests /
 * expense_claims / workflow_requests の4ドメインの申請を、申請者本人の一覧としてステータス
 * 横断で参照するための非正規化テーブル(App\Domain\RequestCenter\Projectors\RequestCenterItemProjector
 * が対象イベントから再生成する。ルートCLAUDE.md「Projectionは再生成可能な派生データ」)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_center_items', function (Blueprint $table) {
            // 元テーブル(paid_leave_requests等)のUUID主キーをそのままこの行のidとして
            // 使う(1申請=1行のため衝突しない。UUIDはドメインをまたいでも一意)。
            $table->uuid('id')->primary();
            $table->string('request_type'); // paid_leave, compensatory_leave, expense_claim, workflow
            $table->uuid('source_id'); // 元テーブルの主キー(詳細画面へのリンクに使用)
            $table->string('status');
            $table->foreignUuid('requester_id')->constrained('users');
            $table->string('title');
            $table->decimal('amount_or_days', 12, 2)->nullable(); // 種別に応じて金額 or 日数
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->index(['requester_id', 'status']);
            $table->index(['requester_id', 'request_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_center_items');
    }
};
