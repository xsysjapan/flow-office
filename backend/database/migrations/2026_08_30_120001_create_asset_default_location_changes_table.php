<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_default_location_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('asset_id')->constrained('assets')->cascadeOnDelete();
            $table->text('location_text');
            $table->foreignUuid('changed_by_user_id')->constrained('users');
            $table->timestamp('changed_at');

            $table->index(['asset_id', 'changed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_default_location_changes');
    }
};
