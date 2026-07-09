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
            $table->id();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
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
