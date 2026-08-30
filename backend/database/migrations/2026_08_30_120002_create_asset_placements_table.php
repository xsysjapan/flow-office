<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_placements', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->text('location_text');
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->foreignUuid('started_by_user_id')->constrained('users');
            $table->foreignUuid('ended_by_user_id')->nullable()->constrained('users');

            $table->index(['asset_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_placements');
    }
};
