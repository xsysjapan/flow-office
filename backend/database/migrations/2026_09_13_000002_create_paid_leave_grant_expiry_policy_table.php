<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 有給休暇の時効(付与日から何年で消滅するか、労働基準法第115条、既定2年)を
     * マスタ化する。`version`で世代管理する(spec.md 論点4)。
     */
    public function up(): void
    {
        Schema::create('paid_leave_grant_expiry_policy', function (Blueprint $table) {
            $table->id();
            $table->string('version');
            $table->unsignedInteger('expiry_years')->default(2);
            $table->date('effective_from')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paid_leave_grant_expiry_policy');
    }
};
