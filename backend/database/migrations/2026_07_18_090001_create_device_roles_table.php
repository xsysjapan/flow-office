<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('device_id')->constrained()->cascadeOnDelete();
            $table->string('role_type');
            $table->json('settings_json')->nullable();
            $table->timestamps();

            $table->unique(['device_id', 'role_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_roles');
    }
};
