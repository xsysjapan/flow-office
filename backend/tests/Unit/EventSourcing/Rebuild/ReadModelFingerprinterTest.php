<?php

namespace Tests\Unit\EventSourcing\Rebuild;

use App\Domain\EventSourcing\Rebuild\ReadModelFingerprinter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ReadModelの照合(件数と行ハッシュ)。作り直し前後で created_at・自動採番id・stored_event_id だけが違う状態は同じ、
 * 値が違えば別になること。
 */
class ReadModelFingerprinterTest extends TestCase
{
    use RefreshDatabase;

    private function createTable(): void
    {
        Schema::create('fingerprint_sample', function (Blueprint $table) {
            $table->id();
            $table->uuid('key');
            $table->string('label')->nullable();
            $table->decimal('days', 5, 1);
            $table->date('work_date');
            $table->json('payload')->nullable();
            $table->unsignedBigInteger('stored_event_id')->nullable();
            $table->timestamps();
        });
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function fill(array $rows): void
    {
        DB::table('fingerprint_sample')->delete();
        DB::table('fingerprint_sample')->insert($rows);
    }

    /** @return array<string, mixed> */
    private function row(string $key, array $override = []): array
    {
        return array_merge([
            'key' => $key,
            'label' => 'a',
            'days' => '1.0',
            'work_date' => '2026-08-10 00:00:00',
            'payload' => '{"b":1,"a":2}',
            'stored_event_id' => 1,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ], $override);
    }

    public function test_created_atと自動採番idとstored_event_idだけが違えばハッシュは一致する(): void
    {
        $this->createTable();
        $keyA = '11111111-1111-1111-1111-111111111111';
        $keyB = '22222222-2222-2222-2222-222222222222';

        $this->fill([$this->row($keyA), $this->row($keyB, ['label' => null])]);
        $before = (new ReadModelFingerprinter)->fingerprint('fingerprint_sample');

        // 並び・id・時刻・stored_event_id・JSONのキー順・小数の書式が違うだけ。
        $this->fill([
            $this->row($keyB, ['id' => 50, 'label' => null, 'created_at' => '2026-10-13 09:00:00', 'updated_at' => '2026-10-13 09:00:00', 'stored_event_id' => 99]),
            $this->row($keyA, ['id' => 7, 'days' => '1', 'payload' => '{"a":2,"b":1}', 'stored_event_id' => null]),
        ]);
        $after = (new ReadModelFingerprinter)->fingerprint('fingerprint_sample');

        $this->assertSame(2, $before['count']);
        $this->assertSame($before, $after);
    }

    public function test_last_changed_event_idだけが違えばハッシュは一致し通常の列が違えば不一致になる(): void
    {
        Schema::create('fingerprint_fiscal', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_calendar_id');
            $table->unsignedTinyInteger('fiscal_year_start_month');
            $table->unsignedTinyInteger('fiscal_year_start_day');
            $table->unsignedBigInteger('last_changed_event_id')->nullable();
            $table->timestamps();
        });
        $row = fn (array $override = []) => array_merge([
            'company_calendar_id' => 1,
            'fiscal_year_start_month' => 5,
            'fiscal_year_start_day' => 1,
            'last_changed_event_id' => null,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ], $override);

        DB::table('fingerprint_fiscal')->insert([$row()]);
        $before = (new ReadModelFingerprinter)->fingerprint('fingerprint_fiscal');

        DB::table('fingerprint_fiscal')->delete();
        DB::table('fingerprint_fiscal')->insert([$row(['last_changed_event_id' => 5])]);
        $after = (new ReadModelFingerprinter)->fingerprint('fingerprint_fiscal');

        $this->assertSame(1, $before['count']);
        $this->assertSame($before, $after);

        DB::table('fingerprint_fiscal')->delete();
        DB::table('fingerprint_fiscal')->insert([$row(['fiscal_year_start_month' => 4, 'last_changed_event_id' => 5])]);
        $changed = (new ReadModelFingerprinter)->fingerprint('fingerprint_fiscal');

        $this->assertNotSame($before['hash'], $changed['hash']);
    }

    public function test_値が1つ違えばハッシュは不一致になる(): void
    {
        $this->createTable();
        $key = '11111111-1111-1111-1111-111111111111';

        $this->fill([$this->row($key)]);
        $base = (new ReadModelFingerprinter)->fingerprint('fingerprint_sample');

        foreach ([['label' => 'b'], ['label' => null], ['days' => '1.5'], ['work_date' => '2026-08-11 00:00:00'], ['key' => '33333333-3333-3333-3333-333333333333']] as $override) {
            $this->fill([$this->row($key, $override)]);
            $changed = (new ReadModelFingerprinter)->fingerprint('fingerprint_sample');
            $this->assertSame(1, $changed['count']);
            $this->assertNotSame($base['hash'], $changed['hash'], json_encode($override));
        }

        $this->fill([$this->row($key), $this->row('44444444-4444-4444-4444-444444444444')]);
        $this->assertSame(2, (new ReadModelFingerprinter)->fingerprint('fingerprint_sample')['count']);
    }

    public function test_nullと空文字は区別される(): void
    {
        $this->createTable();
        $key = '11111111-1111-1111-1111-111111111111';

        $this->fill([$this->row($key, ['label' => null])]);
        $null = (new ReadModelFingerprinter)->fingerprint('fingerprint_sample');
        $this->fill([$this->row($key, ['label' => ''])]);
        $empty = (new ReadModelFingerprinter)->fingerprint('fingerprint_sample');

        $this->assertNotSame($null['hash'], $empty['hash']);
    }

    public function test_行の出力は除外列を含まず名前順でソートされる(): void
    {
        $this->createTable();
        $this->fill([
            $this->row('bbbbbbbb-1111-1111-1111-111111111111'),
            $this->row('aaaaaaaa-1111-1111-1111-111111111111', ['label' => null]),
        ]);

        $rows = (new ReadModelFingerprinter)->rows('fingerprint_sample');

        $this->assertSame([
            '{"days":"1","key":"aaaaaaaa-1111-1111-1111-111111111111","label":"NULL","payload":"{\"a\":2,\"b\":1}","work_date":"2026-08-10"}',
            '{"days":"1","key":"bbbbbbbb-1111-1111-1111-111111111111","label":"a","payload":"{\"a\":2,\"b\":1}","work_date":"2026-08-10"}',
        ], $rows);
    }

    public function test_テーブルが無ければnull(): void
    {
        $this->assertNull((new ReadModelFingerprinter)->fingerprint('no_such_table'));
        $this->assertNull((new ReadModelFingerprinter)->rows('no_such_table'));
    }

    public function test_書き込みをしない(): void
    {
        $this->createTable();
        $this->fill([$this->row('11111111-1111-1111-1111-111111111111')]);
        $before = DB::table('fingerprint_sample')->get()->toArray();

        (new ReadModelFingerprinter)->fingerprint('fingerprint_sample');

        $this->assertEquals($before, DB::table('fingerprint_sample')->get()->toArray());
    }
}
