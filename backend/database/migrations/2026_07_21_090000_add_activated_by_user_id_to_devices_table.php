<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 端末のペアリング用一時トークンを発行した管理者を記録する(docs/23-usecases-devices.md
 * UC-D002)。管理者ICカードの初回登録(ブートストラップ)時に、アクティベーションを
 * 行った本人を判定するために使う。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->foreignUuid('activated_by_user_id')->nullable()->after('owner_user_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('activated_by_user_id');
        });
    }
};
