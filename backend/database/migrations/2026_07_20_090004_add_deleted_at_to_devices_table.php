<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 端末の論理削除(docs/23-usecases-devices.md)。監査証跡(stored_events、
 * 監査ログUC-M003)を残すため物理削除はせず、一覧表示から除外するのみに使う。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
