<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_number_rules', function (Blueprint $table) {
            $table->id();
            // is_default=true の行のみ NULL(デフォルトルール)。デフォルト行を複数
            // 作れてしまわないようアプリ側(ConfigureAssetNumberRuleHandler)で1件までに
            // 制限する(MySQLの部分ユニーク制約非対応のため。spec 論点10)。
            $table->string('category')->nullable()->unique();
            $table->string('prefix');
            $table->unsignedTinyInteger('digit_count')->default(5);
            $table->unsignedInteger('next_number')->default(1);
            $table->boolean('enabled')->default(true);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_number_rules');
    }
};
