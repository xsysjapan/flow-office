<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 「デフォルト働き方」を system_settings.default_work_style_id という間接参照だけでなく
 * work_styles 自身が明示的に持つようにする(指示書 3.2節: 一覧画面でデフォルトを表示・
 * 管理できるようにするため)。system_generated は初回オンボーディングで自動生成された
 * 働き方であることの印で、編集後も通常の働き方として扱えるようにする(指示書 3.1節)。
 *
 * 既存の system_settings.default_work_style_id が指す働き方があれば is_default をbackfillする。
 * ここでは新規のデフォルト働き方を作成しない(未設定なら「未設定」のままとし、
 * オンボーディング画面またはシーダーで明示的に作成させる。指示書 2.2節参照)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_styles', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('is_shift_based');
            $table->boolean('system_generated')->default(false)->after('is_default');
        });

        $defaultWorkStyleId = DB::table('system_settings')->value('default_work_style_id');

        if ($defaultWorkStyleId !== null) {
            DB::table('work_styles')->where('id', $defaultWorkStyleId)->update(['is_default' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('work_styles', function (Blueprint $table) {
            $table->dropColumn(['is_default', 'system_generated']);
        });
    }
};
