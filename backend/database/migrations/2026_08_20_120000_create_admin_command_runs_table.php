<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_command_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('command_name');
            $table->json('parameters');
            $table->string('status')->default('queued');
            $table->foreignUuid('requested_by_user_id')->constrained('users');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->integer('exit_code')->nullable();
            $table->longText('output')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->index(['command_name', 'status']);
        });

        $now = now();
        foreach ([
            'admin_command.view' => ['resource' => 'admin_command', 'action' => 'view', 'description' => '運用コマンド閲覧'],
            'admin_command.execute' => ['resource' => 'admin_command', 'action' => 'execute', 'description' => '運用コマンド実行'],
        ] as $code => $values) {
            DB::table('permissions')->updateOrInsert(['code' => $code], [...$values, 'created_at' => $now, 'updated_at' => $now]);
            $permissionId = DB::table('permissions')->where('code', $code)->value('id');
            DB::table('permission_scope_types')->insertOrIgnore(['permission_id' => $permissionId, 'scope_type' => 'global']);
            $adminRoleId = DB::table('roles')->where('code', 'admin')->value('id');
            if ($adminRoleId !== null) {
                DB::table('permission_role')->insertOrIgnore(['permission_id' => $permissionId, 'role_id' => $adminRoleId]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_command_runs');
        $permissionIds = DB::table('permissions')->whereIn('code', ['admin_command.view', 'admin_command.execute'])->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permission_scope_types')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
