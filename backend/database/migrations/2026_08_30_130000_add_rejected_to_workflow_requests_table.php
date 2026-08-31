<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 汎用「却下」機能(spec 論点2-2)。workflow_requestsに終端状態REJECTEDを追加するための
 * 却下日時・却下理由カラム。全申請種別で共有するworkflow_requests本体への最小限の拡張
 * (ルートCLAUDE.md「絶対に外してはいけない設計原則」14番)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflow_requests', function (Blueprint $table) {
            $table->timestamp('rejected_at')->nullable()->after('cancelled_at');
            $table->text('rejection_reason')->nullable()->after('rejected_at');
        });
    }

    public function down(): void
    {
        Schema::table('workflow_requests', function (Blueprint $table) {
            $table->dropColumn(['rejected_at', 'rejection_reason']);
        });
    }
};
