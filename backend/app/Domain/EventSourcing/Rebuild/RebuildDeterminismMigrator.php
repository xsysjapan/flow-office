<?php

namespace App\Domain\EventSourcing\Rebuild;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * 一括操作の取消イベントの対象行idの補正と、利用者の設定値(タイムゾーン・テナントID)の補正を、1回のトランザクションでまとめて行う
 * (docs/changesets/20261124-user-projection-settings-at-replay/spec.md 論点3)。
 *
 * 2つの補正は別々に順に実行すると成り立たない(一括操作を先にすると作り直しが未補正の利用者イベントで止まり、利用者を先にすると
 * 作り直しで旧idの行が消えて対応表を作れない)。そのため、書き換えの前にライブの表から2つの計画(対応表)を作り、両方を書き換え、
 * `ReadModelRebuilder`で1回だけ作り直し、検証する。合格しなければ全て戻す(バックアップ表は残る)。
 *
 * 検証の合格条件: (1)一括操作の補正の条件(`CalendarBulkOperationTargetIdMigrator::verify`) (2)users・external_identities が書き換え前のライブと一致する
 * (3)他の全表が変わらない(changed_tables が `calendar_bulk_operation_targets` だけ)。
 */
final class RebuildDeterminismMigrator
{
    public function __construct(
        private readonly CalendarBulkOperationTargetIdMigrator $bulk,
        private readonly UserSettingsAtReplayMigrator $user,
    ) {}

    /** 直近の migrate() で作ったバックアップ表の名前(作っていなければnull)。 */
    public function backupTables(): array
    {
        return array_values(array_filter([$this->bulk->backupTable(), $this->user->backupTable()]));
    }

    /**
     * @return array{status: string, dry_run: bool, bulk: array<string, mixed>, user: array<string, mixed>, backup_tables: list<string>, tables?: list<array<string, mixed>>, changed_tables?: int}
     */
    public function migrate(bool $dryRun, float $startedAt): array
    {
        // 後から作ったバックアップの名前が前回のものを返さないよう、毎回リセットする(migrate()の最初に呼ぶ)。
        $this->resetBackups();

        $this->bulk->assertNoSnapshots();

        // 書き換えの前に、ライブの表から2つの計画(対応表)を作る。
        $bulkPlan = $this->bulk->plan();
        $userPlan = $this->user->plan();

        $bulkSummary = $this->bulk->summarize($bulkPlan, $dryRun);
        $userSummary = $this->user->summarize($userPlan, $dryRun);
        $nothing = $bulkPlan['events'] === [] && $userPlan['events'] === [] && $userPlan['unresolved'] === [];
        $summary = [
            'status' => $nothing ? 'nothing' : 'done',
            'dry_run' => $dryRun,
            'bulk' => $bulkSummary,
            'user' => $userSummary,
            'backup_tables' => [],
        ];
        if ($nothing || $dryRun) {
            return $summary;
        }
        $this->bulk->assertExecutable($bulkPlan);
        $this->user->assertExecutable($userPlan);

        if ($bulkPlan['events'] !== []) {
            $this->bulk->createBackup(array_column($bulkPlan['events'], 'id'), null);
        }
        if ($userPlan['events'] !== []) {
            $this->user->createBackup(array_column($userPlan['events'], 'id'), null);
        }
        $summary['backup_tables'] = $this->backupTables();
        $summary['bulk']['backup_table'] = $this->bulk->backupTable();
        $summary['user']['backup_table'] = $this->user->backupTable();
        ReadModelRebuilder::heartbeat();

        $rebuilder = app(ReadModelRebuilder::class);
        $limitSeconds = (int) config('read_model_rebuild.time_limit_minutes', 180) * 60;
        Schema::disableForeignKeyConstraints();
        $level = DB::transactionLevel();
        try {
            DB::beginTransaction();

            $beforeRows = $this->bulk->captureRows();

            $this->bulk->rewriteAll($bulkPlan);
            $this->user->rewriteAll($userPlan);
            $rebuilder->assertWithinTimeLimit($startedAt, $limitSeconds);

            $readModels = $rebuilder->rebuild(false, $startedAt);
            $summary['tables'] = $readModels['tables'];
            $summary['changed_tables'] = $readModels['changed_tables'];
            $summary['other_changed_tables'] = $this->bulk->otherChangedTables($readModels);

            $this->bulk->verify($bulkPlan, $beforeRows, $this->bulk->captureRows());
            $this->user->verify($readModels, [CalendarBulkOperationTargetIdMigrator::TARGET_TABLE]);

            DB::commit();
        } catch (Throwable $e) {
            while (DB::transactionLevel() > $level) {
                DB::rollBack();
            }
            throw $e;
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        return $summary;
    }

    private function resetBackups(): void
    {
        // 各Migratorのバックアップ名は createBackup でだけ設定される。実行ごとに新しいインスタンスを使う前提だが、念のため明示的に空にする。
        $this->bulk->forgetBackup();
        $this->user->forgetBackup();
    }
}
