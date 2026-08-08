<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('employee_number')->nullable()->unique();
            $table->string('account_status')->default('active');
            $table->string('source_type')->default('local');
        });
        Schema::table('system_settings', function (Blueprint $table) {
            $table->boolean('prohibit_self_privileged_role_assignment')->default(false);
        });
        Schema::create('external_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('external_tenant_id')->nullable();
            $table->string('external_subject_id');
            $table->string('external_code')->nullable();
            $table->string('email')->nullable();
            $table->string('status')->default('active');
            $table->timestamp('linked_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'external_tenant_id', 'external_subject_id'], 'external_identity_subject_unique');
            $table->unique(['provider', 'external_subject_id'], 'external_identity_provider_subject_unique');
        });
        Schema::create('group_types', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->unsignedInteger('display_order')->default(0);
            $table->boolean('is_system')->default(false);
            $table->string('status')->default('active');
            $table->string('membership_limit_type')->default('unlimited');
            $table->unsignedInteger('max_memberships_per_user')->nullable();
            $table->boolean('primary_membership_required')->default(false);
            $table->unsignedInteger('max_primary_memberships')->nullable();
            $table->timestamps();
        });
        Schema::create('groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('group_type_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('code')->unique();
            $table->text('description')->nullable();
            $table->foreignUuid('parent_group_id')->nullable()->constrained('groups')->nullOnDelete();
            $table->string('status')->default('active');
            $table->timestamps();
        });
        Schema::create('memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('group_id')->constrained()->cascadeOnDelete();
            $table->string('membership_kind')->default('member');
            $table->boolean('is_primary')->default(false);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'group_id']);
        });
        Schema::create('field_authorities', function (Blueprint $table) {
            $table->id();
            $table->string('field_key')->unique();
            $table->string('authority_type');
            $table->string('provider')->nullable();
            $table->timestamps();
        });
        Schema::create('membership_change_sets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('effective_at');
            $table->string('source_type');
            $table->string('status');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();
        });
        Schema::create('membership_change_items', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('change_set_id')->constrained('membership_change_sets')->cascadeOnDelete();
            $table->string('operation');
            $table->foreignId('group_type_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('from_group_id')->nullable()->constrained('groups')->nullOnDelete();
            $table->foreignUuid('to_group_id')->nullable()->constrained('groups')->nullOnDelete();
            $table->foreignUuid('target_group_id')->nullable()->constrained('groups')->nullOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });
        Schema::create('features', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->foreignId('parent_feature_id')->nullable()->constrained('features')->nullOnDelete();
            $table->string('status')->default('active');
            $table->timestamps();
        });
        Schema::create('group_feature_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feature_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['group_id', 'feature_id']);
        });
        Schema::create('user_feature_suspensions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feature_id')->constrained()->cascadeOnDelete();
            $table->text('reason');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::table('roles', function (Blueprint $table) {
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->string('status')->default('active');
        });
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('resource');
            $table->string('action');
            $table->text('description')->nullable();
            $table->timestamps();
        });
        Schema::create('permission_role', function (Blueprint $table) {
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->primary(['permission_id', 'role_id']);
        });
        Schema::create('role_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('subject_type');
            $table->uuid('subject_id');
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->string('scope_type');
            $table->foreignUuid('scope_group_id')->nullable()->constrained('groups')->nullOnDelete();
            $table->boolean('include_descendants')->default(false);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('status')->default('active');
            $table->foreignUuid('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['subject_type', 'subject_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_assignments');
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('permissions');
        Schema::table('roles', fn (Blueprint $table) => $table->dropColumn(['description', 'is_system', 'status']));
        Schema::dropIfExists('user_feature_suspensions');
        Schema::dropIfExists('group_feature_assignments');
        Schema::dropIfExists('features');
        Schema::dropIfExists('membership_change_items');
        Schema::dropIfExists('membership_change_sets');
        Schema::dropIfExists('field_authorities');
        Schema::dropIfExists('memberships');
        Schema::dropIfExists('groups');
        Schema::dropIfExists('group_types');
        Schema::dropIfExists('external_identities');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['employee_number', 'account_status', 'source_type']));
        Schema::table('system_settings', fn (Blueprint $table) => $table->dropColumn('prohibit_self_privileged_role_assignment'));
    }
};
