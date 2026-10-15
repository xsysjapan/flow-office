<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ワークフローID→休暇申請の対応表(休暇申請文脈が持つ)。
 *
 * ワークフローのイベント(workflow_request.approved/returned/submitted/cancelled)は業務側(subject)を
 * 持たないため、休暇申請文脈はこの表で申請を特定する。`LeaveRequestWorkflowLinkProjector`が
 * workflow_request.drafted(subject_type/subject_id)と休暇申請の*.shared(workflowRequestId)から作る。
 * 派生データ(イベントから再生成可能)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_request_workflow_links', function (Blueprint $table) {
            // ワークフロー(workflow_requests.id)のUUID。イベントの集約IDと同じ値。
            $table->uuid('workflow_request_id')->primary();
            $table->string('leave_kind'); // paid, special, compensatory
            $table->uuid('leave_request_id')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_request_workflow_links');
    }
};
