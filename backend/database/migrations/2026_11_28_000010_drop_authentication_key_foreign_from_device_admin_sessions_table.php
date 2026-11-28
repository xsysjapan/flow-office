<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 端末の管理者の初回の登録(ブートストラップ)は、管理者セッションの開始のイベントに認証キーのIDを入れ、認証キーの文脈の
 * Reactorが同じIDでキーを発行する(変更セット 20261128-remaining-entrypoints 論点3)。同じイベント内ではProjectorが
 * Reactorより先に動くため、セッションの行を作る時点では認証キーの行がまだ無い。別の文脈の行への外部キーは持たない
 * (原則15)ので外部キーを外す(列と索引は残す)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_admin_sessions', function (Blueprint $table) {
            $table->dropForeign(['authentication_key_id']);
        });
    }

    public function down(): void
    {
        Schema::table('device_admin_sessions', function (Blueprint $table) {
            $table->foreign('authentication_key_id')->references('id')->on('authentication_keys')->nullOnDelete();
        });
    }
};
