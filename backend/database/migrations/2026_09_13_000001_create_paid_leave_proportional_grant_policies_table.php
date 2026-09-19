<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `2026_09_09_100001_create_paid_leave_proportional_grant_policies_table.php`が作った
     * 旧スキーマ(`version`が整数・`weekly_scheduled_days`が実日数の整数・`effective_from`/
     * `is_active`列なし)を、比例付与表の新スキーマへ置き換える。方針は
     * `2026_09_13_000000_create_paid_leave_grant_policies_table.php`と同じ
     * (旧テーブル退避→新スキーマ作成→データ変換コピー→旧テーブル削除)。
     *
     * `weekly_scheduled_days`(実日数の整数)は`weekly_scheduled_days_category`
     * (区分キーの文字列)へ変換する。旧データは常に実日数=区分キーの値であるため、
     * 文字列化するだけでよい('4' '3' '2' '1'区分に対応)。
     */
    public function up(): void
    {
        Schema::rename('paid_leave_proportional_grant_policies', 'paid_leave_proportional_grant_policies_legacy');

        Schema::create('paid_leave_proportional_grant_policies', function (Blueprint $table) {
            $table->id();
            $table->string('version');
            $table->string('weekly_scheduled_days_category');
            $table->unsignedInteger('continuous_service_months');
            $table->decimal('grant_days', 4, 1);
            $table->date('effective_from')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['version', 'weekly_scheduled_days_category', 'continuous_service_months'], 'plgpp_version_category_months_unique');
            $table->index(['is_active']);
        });

        foreach (DB::table('paid_leave_proportional_grant_policies_legacy')->orderBy('id')->get() as $legacyRow) {
            DB::table('paid_leave_proportional_grant_policies')->insert([
                'version' => 'v'.$legacyRow->version,
                'weekly_scheduled_days_category' => (string) $legacyRow->weekly_scheduled_days,
                'continuous_service_months' => $legacyRow->continuous_service_months,
                'grant_days' => $legacyRow->grant_days,
                'effective_from' => null,
                'is_active' => true,
                'created_at' => $legacyRow->created_at,
                'updated_at' => $legacyRow->updated_at,
            ]);
        }

        Schema::dropIfExists('paid_leave_proportional_grant_policies_legacy');
    }

    public function down(): void
    {
        Schema::dropIfExists('paid_leave_proportional_grant_policies');
    }
};
