<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MoneyForwardクラウド経費API(ex_transactions)の修正対応。実際のAPI仕様調査
 * (docs/notes/moneyforward-api-investigation.md)により、経費明細作成エンドポイントは
 * `/api/external/v1/offices/{office_id}/office_members/{office_member_id}/ex_transactions`
 * のようにオフィスID(office_id)がURLパスに含まれることが判明した。office_idは連携先
 * (MoneyForward)単位で1つ決まる値のため、external_integration_connectionsへ
 * 保持する(office_member_idはexternal_employee_mappings.external_employee_codeを流用する)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_integration_connections', function (Blueprint $table) {
            $table->string('external_office_id')->nullable()->after('provider');
        });
    }

    public function down(): void
    {
        Schema::table('external_integration_connections', function (Blueprint $table) {
            $table->dropColumn('external_office_id');
        });
    }
};
