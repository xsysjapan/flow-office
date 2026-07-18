<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_import_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('target_month');
            $table->string('status')->default('created');
            $table->string('source_type')->nullable();
            $table->string('source_file_name')->nullable();
            $table->string('source_file_hash')->nullable();
            $table->string('client_type')->nullable();
            $table->foreignId('integration_id')->nullable()->constrained('application_integrations')->nullOnDelete();
            $table->foreignId('monthly_attendance_draft_id')->nullable()->constrained('monthly_attendance_drafts')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'target_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_import_sessions');
    }
};
