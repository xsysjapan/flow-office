<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 月次勤怠下書き(docs/26-usecases-monthly-import.md)。mcp/自身のDBにのみ保持し、
 * backend/には持たせない(CLAUDE.mdの設計原則9、docs/16-database-schema.md参照)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthly_attendance_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('mcp_users');
            $table->string('target_month');
            $table->string('status')->default('draft');
            $table->unsignedInteger('version')->default(1);
            $table->string('source_type')->nullable();
            $table->string('source_reference')->nullable();
            $table->foreignId('created_by_user_id')->constrained('mcp_users');
            $table->dateTime('submitted_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'target_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_attendance_drafts');
    }
};
