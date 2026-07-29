<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UC-X002/UC-X003: 個人・全社共有の移動区間テンプレート。scopeの違いだけで
 * テーブル構造・振る舞いを分けない(docs/30-usecases-expense.md)。
 * イベントソーシング対象外の通常のEloquent CRUD。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_route_templates', function (Blueprint $table) {
            $table->id();
            // personal / company
            $table->string('scope');
            $table->foreignUuid('employee_id')->nullable()->constrained('users');
            $table->string('name');
            $table->string('origin');
            $table->string('destination');
            $table->string('transport_type');
            $table->unsignedInteger('amount');
            $table->foreignId('category_id')->constrained('expense_categories');
            $table->foreignUuid('created_by')->nullable()->constrained('users');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['scope', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_route_templates');
    }
};
