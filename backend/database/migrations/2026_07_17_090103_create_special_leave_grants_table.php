<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 特別休暇の付与(paid_leave_grantsと同じ形)。有給は労基法により2年で時効消滅するため
 * expires_onが必須だが、特別休暇は会社独自の制度のため、付与時に失効日を指定するか、
 * 失効しない付与(expires_on=null)にするかを選べるようにnullableにする。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('special_leave_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('special_leave_type_id')->constrained();
            $table->date('granted_on');
            $table->date('expires_on')->nullable();
            $table->decimal('granted_days', 4, 1);
            $table->decimal('used_days', 4, 1)->default(0);
            $table->decimal('remaining_days', 4, 1);
            $table->string('grant_reason')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'special_leave_type_id', 'expires_on'], 'special_leave_grants_user_type_expires_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('special_leave_grants');
    }
};
