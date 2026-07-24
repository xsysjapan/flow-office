<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('workflow_requests', function (Blueprint $table) {
            // 集約ID(aggregate_id)としてstored_eventsに書き込まれるため、DB採番ではなく
            // コマンド側で生成するUUIDを主キーにする(この行自体もProjector経由で作成できる
            // ようにするため。.claude/skills/add-projection「集約ルートのUUID化」参照)。
            $table->uuid('id')->primary();
            $table->foreignId('request_type_id')->constrained();
            $table->string('title');
            $table->foreignUuid('applicant_user_id')->constrained('users');
            $table->foreignUuid('approver_user_id')->nullable()->constrained('users');
            $table->string('status')->default('draft');
            $table->json('form_data');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['applicant_user_id', 'status']);
            $table->index(['approver_user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflow_requests');
    }
};
