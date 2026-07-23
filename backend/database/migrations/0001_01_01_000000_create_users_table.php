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
        Schema::create('users', function (Blueprint $table) {
            // 集約ID(aggregate_id)としてstored_eventsに書き込まれるため、DB採番ではなく
            // コマンド側で生成するUUIDを主キーにする。行の新規作成自体もUserProjector経由で
            // 行えるようにするため(docs/29-event-sourcing-framework-migration.md参照)。
            $table->uuid('id')->primary();
            $table->string('entra_user_id')->nullable()->unique();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('department')->nullable();
            $table->string('job_title')->nullable();
            $table->string('employment_status')->default('active');
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
