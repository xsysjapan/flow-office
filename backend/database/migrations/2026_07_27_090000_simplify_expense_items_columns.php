<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * expense_items の交通費特化項目(origin/destination/transport_type/destination_name/purpose)を
 * 廃止し、区分に依らない自由記述の description(内容) に統一する。交通費の経路は
 * 「出発地 → 到着地(交通手段)」という所定フォーマットの1行テキストとしてフロントエンドが
 * description に組み立てて送信する(バックエンドは解析しない)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expense_items', function (Blueprint $table) {
            $table->dropColumn(['origin', 'destination', 'transport_type', 'destination_name', 'purpose']);
            $table->string('description', 1000)->nullable()->after('usage_date');
        });
    }

    public function down(): void
    {
        Schema::table('expense_items', function (Blueprint $table) {
            $table->dropColumn('description');
            $table->string('origin')->nullable()->after('usage_date');
            $table->string('destination')->nullable()->after('origin');
            $table->string('transport_type')->nullable()->after('destination');
            $table->string('destination_name')->nullable()->after('amount');
            $table->text('purpose')->nullable()->after('destination_name');
        });
    }
};
