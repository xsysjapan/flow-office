<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('request_types', function (Blueprint $table) {
            // UC-W001 手順2: 添付必須有無・サイズ/拡張子の許可リスト。
            $table->boolean('requires_attachment')->default(false)->after('form_schema');
            $table->unsignedInteger('attachment_max_size_kb')->nullable()->after('requires_attachment');
            $table->json('attachment_allowed_extensions')->nullable()->after('attachment_max_size_kb');
            // UC-W001 手順4: 申請可能な対象者(ロールコードの配列)。nullなら全員が申請可能。
            $table->json('eligible_role_codes')->nullable()->after('attachment_allowed_extensions');
            // UC-B001 手順4: バックオフィスタスクの初期処理部署。
            $table->string('backoffice_department')->nullable()->after('backoffice_task_type');
            // UC-B004 手順5: 会計/振込CSV出力の対象にするかどうかと、金額として扱うform_dataのキー。
            $table->string('export_amount_field')->nullable()->after('backoffice_department');
            // UC-B003: バックオフィスタスクのステータス遷移(task_typeごとに異なるため、
            // 申請種別マスタ側で定義する)。{from_status: [to_status, ...]} の形式。
            // 未設定(null)なら遷移制限なし(全ステータス間を自由に遷移可能)。
            $table->json('allowed_status_transitions')->nullable()->after('export_amount_field');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('request_types', function (Blueprint $table) {
            $table->dropColumn([
                'requires_attachment',
                'attachment_max_size_kb',
                'attachment_allowed_extensions',
                'eligible_role_codes',
                'backoffice_department',
                'export_amount_field',
                'allowed_status_transitions',
            ]);
        });
    }
};
