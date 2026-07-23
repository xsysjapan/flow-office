<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_scopes', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('integration_id')->constrained('application_integrations')->cascadeOnDelete();
            $table->string('scope');
            $table->timestamps();

            $table->unique(['integration_id', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_scopes');
    }
};
