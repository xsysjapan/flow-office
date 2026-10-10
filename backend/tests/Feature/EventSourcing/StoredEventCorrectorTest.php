<?php

namespace Tests\Feature\EventSourcing;

use App\Console\Commands\Concerns\StoredEventCorrectionCommand;
use App\Domain\EventSourcing\Correction\StoredEventCorrectionPlan;
use App\Domain\EventSourcing\Correction\StoredEventCorrector;
use App\Domain\EventSourcing\Correction\StoredEventRewrite;
use Illuminate\Foundation\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * stored_events の直接修正の枠組み(StoredEventCorrector・StoredEventCorrectionCommand)の検証
 * (.claude/skills/data-correction の安全手順)。
 *
 * - 試し実行は何も変えない。本実行はバックアップを作ってから1トランザクションで行い、補正ログへ修正前を残す。
 * - 同じ補正キーの再実行は修正済みの行を触らない(冪等)。
 * - 削除で同じ集約の版に欠番が生じる場合は、全体を巻き戻す。欠番が生じない削除は許可する。
 */
class StoredEventCorrectorTest extends TestCase
{
    use RefreshDatabase;

    private StoredEventCorrector $corrector;

    private string $streamId;

    /** @var list<int> stored_events.id(版1,2,3) */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->corrector = app(StoredEventCorrector::class);
        $this->streamId = (string) Str::uuid();
        $this->ids = [];
        foreach ([1, 2, 3] as $version) {
            $this->ids[] = $this->insertEvent($this->streamId, $version, 'test.recorded', ['value' => "v{$version}", 'workType' => 'paid_leave_full']);
        }
    }

    public function test_a_preview_lists_the_change_and_writes_nothing(): void
    {
        $before = $this->eventRows();

        $rows = $this->corrector->preview($this->plan(StoredEventRewrite::rewrite($this->ids[1], ['value' => 'fixed'])));

        $this->assertCount(1, $rows);
        $this->assertSame('pending', $rows[0]['status']);
        $this->assertSame(['value' => 'v2', 'workType' => 'paid_leave_full'], $rows[0]['before']);
        $this->assertSame(['value' => 'fixed'], $rows[0]['after']);
        $this->assertSame(2, $rows[0]['aggregate_version']);
        $this->assertSame($before, $this->eventRows());
        $this->assertSame(0, DB::table(StoredEventCorrector::LOG_TABLE)->count(), '試し実行は補正ログも書かない');
    }

    public function test_apply_rewrites_the_payload_keeps_a_backup_and_logs_the_previous_payload(): void
    {
        $plan = $this->plan(StoredEventRewrite::rewrite($this->ids[1], ['value' => 'fixed']));

        $result = $this->corrector->apply($plan, 'stored_events_backup_test_rewrite');

        $this->assertSame(1, $result->applied);
        $this->assertSame(0, $result->alreadyApplied);
        $this->assertSame('stored_events_backup_test_rewrite', $result->backupTable);

        $this->assertSame(['value' => 'fixed'], $this->payload($this->ids[1]));
        $this->assertSame(['value' => 'v1', 'workType' => 'paid_leave_full'], $this->payload($this->ids[0]), '他の行は変えない');
        $this->assertSame(2, (int) DB::table('stored_events')->where('id', $this->ids[1])->value('aggregate_version'), '版は変えない');

        $backup = DB::table('stored_events_backup_test_rewrite')->where('id', $this->ids[1])->first();
        $this->assertSame(['value' => 'v2', 'workType' => 'paid_leave_full'], json_decode((string) $backup->event_properties, true));

        $log = DB::table(StoredEventCorrector::LOG_TABLE)->where('correction_key', 'test-rewrite')->get();
        $this->assertCount(1, $log);
        $this->assertSame($this->ids[1], (int) $log[0]->stored_event_id);
        $this->assertSame('rewrite', $log[0]->operation);
        $this->assertSame(['value' => 'v2', 'workType' => 'paid_leave_full'], json_decode((string) $log[0]->before_event_properties, true));
        $this->assertSame('stored_events_backup_test_rewrite', $log[0]->backup_table);
    }

    public function test_applying_the_same_plan_again_changes_nothing_and_makes_no_new_backup(): void
    {
        $plan = $this->plan(StoredEventRewrite::rewrite($this->ids[1], ['value' => 'fixed']));
        $this->corrector->apply($plan, 'stored_events_backup_test_first');
        $afterFirst = $this->eventRows();

        $result = $this->corrector->apply($plan, 'stored_events_backup_test_second');

        $this->assertSame(0, $result->applied);
        $this->assertSame(1, $result->alreadyApplied);
        $this->assertNull($result->backupTable);
        $this->assertFalse(Schema::hasTable('stored_events_backup_test_second'));
        $this->assertSame($afterFirst, $this->eventRows());
        $this->assertSame(1, DB::table(StoredEventCorrector::LOG_TABLE)->where('correction_key', 'test-rewrite')->count());
    }

    public function test_deleting_the_last_version_is_allowed_and_keeps_the_versions_continuous(): void
    {
        $this->corrector->apply($this->plan(StoredEventRewrite::delete($this->ids[2])), 'stored_events_backup_test_delete_last');

        $this->assertFalse(DB::table('stored_events')->where('id', $this->ids[2])->exists());
        $this->assertSame([1, 2], $this->versions());
        $this->assertSame('delete', DB::table(StoredEventCorrector::LOG_TABLE)->where('correction_key', 'test-rewrite')->value('operation'));
    }

    public function test_deleting_a_middle_version_leaves_a_gap_and_is_rolled_back(): void
    {
        $before = $this->eventRows();

        try {
            $this->corrector->apply($this->plan(StoredEventRewrite::delete($this->ids[1])), 'stored_events_backup_test_gap');
            $this->fail('版の欠番が生じる削除は拒否されること');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('版が連続しません', $exception->getMessage());
        }

        $this->assertSame($before, $this->eventRows(), '全体が巻き戻されること');
        $this->assertSame(0, DB::table(StoredEventCorrector::LOG_TABLE)->count());
    }

    public function test_the_backup_table_name_must_be_valid_and_new(): void
    {
        $plan = $this->plan(StoredEventRewrite::rewrite($this->ids[1], ['value' => 'fixed']));

        $this->expectException(InvalidArgumentException::class);
        $this->corrector->apply($plan, 'Bad-Name');
    }

    public function test_an_existing_backup_table_is_not_overwritten(): void
    {
        DB::statement('CREATE TABLE stored_events_backup_test_exists (id INTEGER)');

        $this->expectException(RuntimeException::class);
        $this->corrector->apply($this->plan(StoredEventRewrite::rewrite($this->ids[1], ['value' => 'fixed'])), 'stored_events_backup_test_exists');
    }

    public function test_a_missing_target_that_was_not_applied_is_an_error(): void
    {
        $this->expectException(RuntimeException::class);
        $this->corrector->preview($this->plan(StoredEventRewrite::rewrite(999999, ['value' => 'x'])));
    }

    public function test_the_command_previews_by_default_and_applies_with_the_apply_option(): void
    {
        $plan = $this->plan(StoredEventRewrite::rewrite($this->ids[1], ['value' => 'fixed']));
        $command = new class($plan) extends StoredEventCorrectionCommand
        {
            protected $signature = 'test:stored-event-correction {--apply} {--backup-table=}';

            protected $description = '補正枠組みのテスト用コマンド';

            public function __construct(private readonly StoredEventCorrectionPlan $planToRun)
            {
                parent::__construct();
            }

            protected function plan(): StoredEventCorrectionPlan
            {
                return $this->planToRun;
            }
        };
        app(Kernel::class)->registerCommand($command);

        $this->artisan('test:stored-event-correction')->assertSuccessful();
        $this->assertSame(['value' => 'v2', 'workType' => 'paid_leave_full'], $this->payload($this->ids[1]), '既定は試し実行');

        $this->artisan('test:stored-event-correction', ['--apply' => true, '--backup-table' => 'stored_events_backup_test_cmd'])
            ->assertSuccessful();
        $this->assertSame(['value' => 'fixed'], $this->payload($this->ids[1]));
        $this->assertTrue(Schema::hasTable('stored_events_backup_test_cmd'));
    }

    private function plan(StoredEventRewrite $rewrite): StoredEventCorrectionPlan
    {
        return new StoredEventCorrectionPlan(
            correctionKey: 'test-rewrite',
            description: 'テスト用の直接修正',
            rewrites: [$rewrite],
        );
    }

    /** @param  array<string, mixed>  $properties */
    private function insertEvent(string $aggregateUuid, int $version, string $eventClass, array $properties): int
    {
        return (int) DB::table('stored_events')->insertGetId([
            'aggregate_uuid' => $aggregateUuid,
            'aggregate_version' => $version,
            'event_version' => 1,
            'event_class' => $eventClass,
            'event_properties' => json_encode($properties, JSON_THROW_ON_ERROR),
            'meta_data' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(int $id): array
    {
        return json_decode((string) DB::table('stored_events')->where('id', $id)->value('event_properties'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<int> */
    private function versions(): array
    {
        return DB::table('stored_events')->where('aggregate_uuid', $this->streamId)->orderBy('aggregate_version')
            ->pluck('aggregate_version')->map(fn ($v) => (int) $v)->all();
    }

    /** @return list<array<string, mixed>> */
    private function eventRows(): array
    {
        return DB::table('stored_events')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }
}
