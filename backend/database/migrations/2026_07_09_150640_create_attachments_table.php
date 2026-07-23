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
        Schema::create('attachments', function (Blueprint $table) {
            // 集約ID(aggregate_uuid)としてstored_eventsに書き込まれるため、DB採番ではなく
            // コマンド側で生成するUUIDを主キーにする。行の新規作成自体もAttachmentProjector経由で
            // 行えるようにするため(docs/28-event-sourcing-framework-migration.md参照)。
            $table->uuid('id')->primary();
            $table->string('owner_type');
            // ポリモーフィックな所有者のID。attendance_day は数値ID、workflow_request は
            // UUID なので string にする(混在するため型を固定できない)。
            $table->string('owner_id');
            $table->foreignId('uploaded_by')->constrained('users');
            $table->string('file_name');
            $table->string('stored_path');
            $table->string('mime_type');
            $table->unsignedBigInteger('file_size');
            $table->timestamps();

            $table->index(['owner_type', 'owner_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
