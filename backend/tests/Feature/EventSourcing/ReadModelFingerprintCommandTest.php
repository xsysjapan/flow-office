<?php

namespace Tests\Feature\EventSourcing;

use App\Domain\EventSourcing\Rebuild\ReadModelTableLister;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * leave:rebuild-event-store --readmodel-fingerprint / --readmodel-fingerprint-rows の出力。
 */
class ReadModelFingerprintCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_fingerprintは1行1テーブルでタブ区切りの件数とハッシュを出す(): void
    {
        $this->assertSame(0, Artisan::call('leave:rebuild-event-store', ['--readmodel-fingerprint' => true]));
        $lines = array_values(array_filter(explode("\n", Artisan::output())));

        $tables = array_keys((new ReadModelTableLister)->tables(app_path('Domain')));
        $this->assertCount(count($tables), $lines);
        foreach ($lines as $i => $line) {
            $parts = explode("\t", $line);
            $this->assertCount(3, $parts, $line);
            $this->assertSame($tables[$i], $parts[0]);
            $this->assertMatchesRegularExpression('/^\d+$/', $parts[1]);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $parts[2]);
        }
    }

    public function test_rowsは指定テーブルの正規化した行を出す(): void
    {
        foreach (['aaaaaaaa', 'bbbbbbbb'] as $prefix) {
            DB::table('devices')->insert([
                'id' => $prefix.'-0000-0000-0000-000000000000',
                'owner_type' => 'shared',
                'name' => $prefix,
                'device_type' => 'android',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        DB::table('device_roles')->insert([
            ['device_id' => 'bbbbbbbb-0000-0000-0000-000000000000', 'role_type' => 'punch', 'settings_json' => '{"y":1,"x":2}', 'created_at' => now(), 'updated_at' => now()],
            ['device_id' => 'aaaaaaaa-0000-0000-0000-000000000000', 'role_type' => 'punch', 'settings_json' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->assertSame(0, Artisan::call('leave:rebuild-event-store', ['--readmodel-fingerprint-rows' => 'device_roles']));
        $lines = array_values(array_filter(explode("\n", Artisan::output())));

        $this->assertSame([
            '{"device_id":"aaaaaaaa-0000-0000-0000-000000000000","role_type":"punch","settings_json":"NULL"}',
            '{"device_id":"bbbbbbbb-0000-0000-0000-000000000000","role_type":"punch","settings_json":"{\"x\":2,\"y\":1}"}',
        ], $lines);
    }

    public function test_rowsは一覧に無いテーブルを拒否する(): void
    {
        $this->artisan('leave:rebuild-event-store', ['--readmodel-fingerprint-rows' => 'permissions'])->assertExitCode(1);
    }
}
