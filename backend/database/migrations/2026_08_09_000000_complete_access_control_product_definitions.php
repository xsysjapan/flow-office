<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('features', function (Blueprint $table) {
            $table->unsignedInteger('display_order')->default(0)->after('parent_feature_id');
            $table->boolean('is_selectable')->default(true)->after('display_order');
        });

        Schema::create('permission_scope_types', function (Blueprint $table) {
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->string('scope_type');
            $table->primary(['permission_id', 'scope_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_scope_types');
        Schema::table('features', function (Blueprint $table) {
            $table->dropColumn(['display_order', 'is_selectable']);
        });
    }
};
