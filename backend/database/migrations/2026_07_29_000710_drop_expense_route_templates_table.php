<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 交通費の入力は移動区間テンプレート専用の仕組みから、経費全体で共通の入力プリセット
 * (expense_entry_presets)に一本化する。本番データが存在しないため、テーブルごと廃止する
 * (docs/30-usecases-expense.md改定、経費精算機能 設計・実装指示書9〜10)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('expense_route_templates');
    }

    public function down(): void
    {
        Schema::create('expense_route_templates', function (Blueprint $table) {
            $table->id();
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
};
