<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/changesets/20260913-paid-leave-fiscal-grant-and-nav/spec.md 論点(前倒し一斉付与)を
 * PR#112のScheduleCandidateGenerator構造へ移植(20260914-port-to-pr112)。
 * `grant_cycle_type`: 'anniversary'(既定・従来通りhire_date起算の周年サイクル)/
 * 'mass_grant_month'(年度一斉付与月への前倒し)。
 * `mass_grant_month`: 'mass_grant_month'選択時のみ使う一斉付与月(1-12)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paid_leave_grant_rules', function (Blueprint $table) {
            $table->string('grant_cycle_type')->default('anniversary')->after('name');
            $table->unsignedTinyInteger('mass_grant_month')->nullable()->after('grant_cycle_type');
        });
    }

    public function down(): void
    {
        Schema::table('paid_leave_grant_rules', function (Blueprint $table) {
            $table->dropColumn(['grant_cycle_type', 'mass_grant_month']);
        });
    }
};
