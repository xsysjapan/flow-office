<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            // 集約ID(aggregate_id)としてstored_eventsに書き込まれるため、DB採番ではなく
            // コマンド側で生成できるUUIDにする(AssetProjector経由で行を作成できるようにするため。
            // .claude/skills/add-projection「集約ルートのUUID化」参照)。
            $table->uuid('id')->primary();
            $table->string('asset_no')->unique();
            $table->string('name');
            $table->string('category');
            $table->string('serial_number')->nullable();
            $table->string('management_type'); // lending, installation
            $table->string('lending_status')->nullable(); // available, loaned, repair, lost, disposed
            $table->string('installation_status')->nullable(); // stored, installed, repair, lost, disposed
            $table->string('lending_method')->nullable(); // self_service, backoffice, approval
            $table->text('default_location_text')->nullable();
            $table->string('qr_token')->unique();
            $table->uuid('current_loan_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('management_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
