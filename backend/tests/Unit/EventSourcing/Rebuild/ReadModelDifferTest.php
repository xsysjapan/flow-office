<?php

namespace Tests\Unit\EventSourcing\Rebuild;

use App\Domain\EventSourcing\Rebuild\ReadModelDiffer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** ReadModelDiffer: 2つのSQLite(:memory:)接続で、日時差の集計・主キーあり/なし・新設テーブル・片側のみの行を確かめる。 */
class ReadModelDifferTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['diff_current', 'diff_other'] as $name) {
            config(["database.connections.{$name}" => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]]);
        }
    }

    private function keyed(string $connection): void
    {
        Schema::connection($connection)->create('keyed', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('label')->nullable();
            $table->dateTime('at')->nullable();
            $table->unsignedBigInteger('stored_event_id')->nullable();
            $table->timestamps();
        });
    }

    private function differ(): ReadModelDiffer
    {
        return new ReadModelDiffer('diff_current', 'diff_other');
    }

    public function test_主キーありは変化を列ごとに数え日時の差を秒で集計する(): void
    {
        $this->keyed('diff_current');
        $this->keyed('diff_other');
        $now = '2026-01-01 00:00:00';
        $row = fn (string $id, string $label, string $at, int $event) => ['id' => $id, 'label' => $label, 'at' => $at, 'stored_event_id' => $event, 'created_at' => $now, 'updated_at' => $now];

        DB::connection('diff_other')->table('keyed')->insert([
            $row('a', 'x', '2026-08-10 00:00:00', 1),
            $row('b', 'x', '2026-08-10 00:00:00', 1),
            $row('c', 'old', '2026-08-10 00:00:00', 1),
            $row('d', 'x', '2026-08-10 00:00:00', 1),
        ]);
        DB::connection('diff_current')->table('keyed')->insert([
            $row('a', 'x', '2026-08-10 09:00:00', 99), // stored_event_id は除外列、at は +32400
            $row('b', 'x', '2026-08-10 09:00:00', 2),  // +32400
            $row('c', 'new', '2026-08-09 23:59:00', 3), // label 変化、at -60
            $row('e', 'x', '2026-08-10 00:00:00', 4),  // 現在のみ(dは比較先のみ)
        ]);

        $d = $this->differ()->diffTable('keyed');

        $this->assertSame('compared', $d['status']);
        $this->assertSame('id', $d['key']);
        $this->assertSame([4, 4], [$d['count_other'], $d['count_current']]);
        $this->assertSame(1, $d['only_other']);
        $this->assertSame(1, $d['only_current']);
        $this->assertSame(3, $d['changed']);
        $this->assertSame(['at' => 3, 'label' => 1], $d['changed_columns']);
        $this->assertSame(['at' => ['-60' => 1, '+32400' => 2]], $d['datetime_diff_seconds']);
        $this->assertSame('d', $d['only_other_examples'][0]['key']);
        $this->assertSame('e', $d['only_current_examples'][0]['key']);
        $this->assertSame(32400, $d['changed_examples'][0]['diff_seconds']);
    }

    public function test_例は最大5件(): void
    {
        $this->keyed('diff_current');
        $this->keyed('diff_other');
        $now = '2026-01-01 00:00:00';
        foreach (range(1, 8) as $i) {
            DB::connection('diff_other')->table('keyed')->insert(['id' => "k{$i}", 'label' => 'x', 'at' => null, 'stored_event_id' => null, 'created_at' => $now, 'updated_at' => $now]);
        }

        $d = $this->differ()->diffTable('keyed');

        $this->assertSame(8, $d['only_other']);
        $this->assertCount(5, $d['only_other_examples']);
    }

    public function test_自動採番の主キーは比較から除き全列の組で両側にだけある行として数える(): void
    {
        foreach (['diff_current', 'diff_other'] as $c) {
            Schema::connection($c)->create('auto', function (Blueprint $table) {
                $table->id();
                $table->string('label');
                $table->dateTime('at');
                $table->timestamps();
            });
        }
        DB::connection('diff_other')->table('auto')->insert([
            ['id' => 1, 'label' => 'same', 'at' => '2026-08-10 00:00:00'],
            ['id' => 2, 'label' => 'old', 'at' => '2026-08-10 00:00:00'],
        ]);
        DB::connection('diff_current')->table('auto')->insert([
            ['id' => 10, 'label' => 'same', 'at' => '2026-08-10 00:00:00'],
            ['id' => 11, 'label' => 'old', 'at' => '2026-08-10 09:00:00'],
        ]);

        $d = $this->differ()->diffTable('auto');

        $this->assertNull($d['key']);
        $this->assertSame(0, $d['changed']);
        $this->assertSame(1, $d['only_other']);
        $this->assertSame(1, $d['only_current']);
        $this->assertSame('old', $d['only_other_examples'][0]['row']['label']);
        $this->assertSame('2026-08-10 09:00:00', $d['only_current_examples'][0]['row']['at']);
    }

    public function test_比較先にテーブルが無ければ新設として件数だけ出す(): void
    {
        $this->keyed('diff_current');
        DB::connection('diff_current')->table('keyed')->insert(['id' => 'a', 'label' => 'x', 'at' => null, 'stored_event_id' => null, 'created_at' => null, 'updated_at' => null]);

        $result = $this->differ()->diff(['keyed']);

        $this->assertSame(['status' => 'new', 'count_other' => null, 'count_current' => 1], $result['keyed']);
        $this->assertSame([['keyed', '-', 1, '-', '-', '-', '新設']], ReadModelDiffer::summaryRows($result));
    }

    public function test_片側にしかない列は比較から除き一覧に出す(): void
    {
        $this->keyed('diff_current');
        Schema::connection('diff_other')->create('keyed', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('label')->nullable();
            $table->string('legacy')->nullable();
        });
        DB::connection('diff_current')->table('keyed')->insert(['id' => 'a', 'label' => 'x', 'at' => null, 'stored_event_id' => null, 'created_at' => null, 'updated_at' => null]);
        DB::connection('diff_other')->table('keyed')->insert(['id' => 'a', 'label' => 'x', 'legacy' => 'y']);

        $d = $this->differ()->diffTable('keyed');

        $this->assertSame(0, $d['changed']);
        $this->assertSame(['at'], $d['columns_only_current']);
        $this->assertSame(['legacy'], $d['columns_only_other']);
    }
}
