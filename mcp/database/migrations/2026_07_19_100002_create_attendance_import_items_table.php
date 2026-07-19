<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_import_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_session_id')->constrained('attendance_import_sessions')->cascadeOnDelete();
            $table->date('work_date');
            $table->json('proposed_data_json');
            $table->json('existing_data_json')->nullable();
            $table->json('differences_json')->nullable();
            $table->json('validation_result_json')->nullable();
            $table->string('confidence')->nullable();
            $table->string('status')->default('pending_review');
            $table->json('source_reference_json')->nullable();
            $table->timestamps();

            $table->index(['import_session_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_import_items');
    }
};
