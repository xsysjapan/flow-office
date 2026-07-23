<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authentication_key_device_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('authentication_key_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('device_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('site_id')->nullable();
            $table->boolean('allow')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authentication_key_device_rules');
    }
};
