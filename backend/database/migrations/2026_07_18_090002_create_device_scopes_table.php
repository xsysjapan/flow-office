<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_scopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('scope');
            $table->timestamps();

            $table->unique(['device_id', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_scopes');
    }
};
