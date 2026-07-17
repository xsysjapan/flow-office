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
        Schema::create('backoffice_tasks', function (Blueprint $table) {
            // workflow_requests と同様、Projector経由で作成できるようコマンド側生成のUUIDを
            // 主キーにする(.claude/skills/add-projection「集約ルートのUUID化」参照)。
            $table->uuid('id')->primary();
            $table->string('source_type');
            // ポリモーフィックな発生源のID(workflow_request は UUID)。将来の発生源が
            // 数値IDでも文字列として保持できるよう string にする。
            $table->string('source_id');
            $table->string('task_type');
            $table->string('title');
            $table->string('status')->default('not_started');
            $table->string('assigned_department')->nullable();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users');
            $table->date('due_on')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['source_type', 'source_id']);
            $table->index(['status', 'assigned_department']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('backoffice_tasks');
    }
};
