<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 各入力項目の値の出所(docs/26-usecases-monthly-import.md「AI生成値の出所管理」、
 * docs/03-architecture.md 3.7)。ポリモーフィックにmonthly_attendance_drafts/
 * attendance_import_items等を指す。
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
            $table->foreignId('confirmed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
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
