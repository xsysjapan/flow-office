<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `2026_09_09_100002_create_paid_leave_grant_expiry_policy_table.php`が作った旧スキーマ
     * (`version`が整数・`effective_from`/`is_active`列なし)を新スキーマへ置き換える。方針は
     * `2026_09_13_000000_create_paid_leave_grant_policies_table.php`と同じ。
     */
    public function up(): void
    {
        Schema::rename('paid_leave_grant_expiry_policy', 'paid_leave_grant_expiry_policy_legacy');

        Schema::create('paid_leave_grant_expiry_policy', function (Blueprint $table) {
            $table->id();
            $table->string('version');
            $table->unsignedInteger('expiry_years')->default(2);
            $table->date('effective_from')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // 旧テーブルの`version`列は`->unique()`(列チェーン)で定義されており、
            // Laravelの自動生成名は新テーブルの`unique(['version'])`と偶然同じ
            // (`paid_leave_grant_expiry_policy_version_unique`)になる。SQLiteは
            // `Schema::rename()`後もインデックス名を新テーブル名に追従させないため、
            // 明示的に別名を指定して衝突を避ける。
            $table->unique(['version'], 'paid_leave_grant_expiry_policy_version_unique_v2');
        });

        foreach (DB::table('paid_leave_grant_expiry_policy_legacy')->orderBy('id')->get() as $legacyRow) {
            DB::table('paid_leave_grant_expiry_policy')->insert([
                'version' => 'v'.$legacyRow->version,
                'expiry_years' => $legacyRow->expiry_years,
                'effective_from' => null,
                'is_active' => true,
                'created_at' => $legacyRow->created_at,
                'updated_at' => $legacyRow->updated_at,
            ]);
        }

        Schema::dropIfExists('paid_leave_grant_expiry_policy_legacy');
    }

    public function down(): void
    {
        Schema::dropIfExists('paid_leave_grant_expiry_policy');
    }
};
