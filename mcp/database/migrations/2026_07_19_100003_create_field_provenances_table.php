<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 各入力項目の値の出所(docs/26-usecases-monthly-import.md「AI生成値の出所管理」)。
 * ポリモーフィックにmonthly_attendance_drafts等を指す(Eloquentのmorphmapは使わず、
 * entity_type/entity_idの単純な文字列参照)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_provenances', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->string('field_name');
            $table->string('source_type');
            $table->json('source_reference_json')->nullable();
            $table->string('confidence')->nullable();
            $table->text('previous_value')->nullable();
            $table->foreignId('confirmed_by_user_id')->nullable()->constrained('mcp_users')->nullOnDelete();
            $table->dateTime('confirmed_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_provenances');
    }
};
