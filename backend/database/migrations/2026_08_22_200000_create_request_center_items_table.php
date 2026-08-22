<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 「申請センター」画面向けの横断Projection。paid_leave_requests / compensatory_leave_requests /
 * expense_claims / workflow_requests の4ドメインの「申請(承認ワークフロー)」を、申請者本人の
 * 一覧としてステータス横断で参照するための非正規化テーブル
 * (App\Domain\RequestCenter\Projectors\RequestCenterItemProjector が対象イベントから
 * 再生成する。ルートCLAUDE.md「Projectionは再生成可能な派生データ」)。
 *
 * ここに持たせるのは承認ワークフロー共通の情報(申請種別・ステータス・申請者・承認者・
 * タイトル・提出日時)とdetailへのポインタ(request_type + source_id)のみで、各業務ドメイン
 * 固有の未確定ステート・金額集計・残高計算等は持たない(既存の各業務Projectionの責務のまま)。
 * 詳細が必要な画面はrequest_type + source_idを使って元の業務ドメインのAPI/画面へ遷移する。
 *
 * また、このテーブルは「申請(承認ワークフローに乗ったもの)が存在する場合のみのビュー」
 * である。管理者による手動付与(compensatory_leave.manually_granted等、申請を経由しない
 * 業務データ)は対象イベントに含めておらず、この一覧には現れない。
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
            $table->foreignUuid('approver_id')->nullable()->constrained('users');
            $table->string('title');
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
