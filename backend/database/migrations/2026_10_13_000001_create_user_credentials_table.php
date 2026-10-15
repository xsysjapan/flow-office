<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ローカルログインのパスワードのハッシュとremember_tokenをusersから専用の表へ分離する
 * (docs/changesets/20261009-keep-leave-work-type-on-edit/spec.md 論点19 決定7)。
 * user_credentialsはsystem_settingsと同じく、イベントではなく直接更新する正データで、
 * Projectorの書き込み先ではない。usersをイベントから再生成しても資格情報が消えないよう、
 * usersへの外部キーは張らない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_credentials', function (Blueprint $table) {
            $table->uuid('user_id')->primary();
            $table->string('password')->nullable();
            $table->string('remember_token', 100)->nullable();
            $table->timestamps();
        });

        $hasPassword = Schema::hasColumn('users', 'password');
        $hasRememberToken = Schema::hasColumn('users', 'remember_token');

        if ($hasPassword || $hasRememberToken) {
            $columns = ['id'];
            if ($hasPassword) {
                $columns[] = 'password';
            }
            if ($hasRememberToken) {
                $columns[] = 'remember_token';
            }
            $now = now();
            foreach (DB::table('users')->select($columns)->get() as $row) {
                $password = $hasPassword ? $row->password : null;
                $rememberToken = $hasRememberToken ? $row->remember_token : null;
                if ($password === null && $rememberToken === null) {
                    continue;
                }
                DB::table('user_credentials')->insert([
                    'user_id' => $row->id,
                    'password' => $password,
                    'remember_token' => $rememberToken,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        foreach (['password', 'remember_token'] as $column) {
            if (Schema::hasColumn('users', $column)) {
                Schema::table('users', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->after('email');
            $table->string('remember_token', 100)->nullable();
        });

        foreach (DB::table('user_credentials')->get() as $row) {
            DB::table('users')->where('id', $row->user_id)->update([
                'password' => $row->password,
                'remember_token' => $row->remember_token,
            ]);
        }

        Schema::dropIfExists('user_credentials');
    }
};
