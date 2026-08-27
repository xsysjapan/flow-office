<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * flow-office側のuser_idと、外部の会計・労務クラウド(freee/moneyforward)側の従業員番号との
 * 対応表(docs/33-usecases-attendance-external-api.md)。連携先ごとに1レコード。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_employee_mappings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('provider'); // freee, moneyforward
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('external_employee_code');
            $table->timestamps();

            $table->unique(['provider', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_employee_mappings');
    }
};
