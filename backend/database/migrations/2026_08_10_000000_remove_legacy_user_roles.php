<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('role_user')) {
            return;
        }

        DB::table('role_user')->orderBy('user_id')->each(function (object $legacy): void {
            $id = Uuid::uuid5(Uuid::NAMESPACE_URL, "migrated-user-role-assignment:{$legacy->user_id}:{$legacy->role_id}")->toString();
            $roleCode = DB::table('roles')->where('id', $legacy->role_id)->value('code');
            $scopeType = match ($roleCode) {
                'employee' => 'self',
                'backoffice_staff' => 'approval_task',
                default => 'global',
            };
            DB::table('role_assignments')->updateOrInsert(
                ['id' => $id],
                [
                    'subject_type' => 'user',
                    'subject_id' => $legacy->user_id,
                    'role_id' => $legacy->role_id,
                    'scope_type' => $scopeType,
                    'status' => 'active',
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        });

        Schema::drop('role_user');
    }

    public function down(): void
    {
        Schema::create('role_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'role_id']);
        });

        DB::table('role_assignments')
            ->where('subject_type', 'user')
            ->each(function (object $assignment): void {
                DB::table('role_user')->updateOrInsert([
                    'user_id' => $assignment->subject_id,
                    'role_id' => $assignment->role_id,
                ], ['created_at' => now(), 'updated_at' => now()]);
            });
    }
};
