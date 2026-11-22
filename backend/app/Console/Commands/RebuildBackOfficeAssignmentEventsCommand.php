<?php

namespace App\Console\Commands;

use App\Domain\EventSourcing\Rebuild\BackOfficeTaskAssignmentRebuildVerifier;
use App\Domain\EventSourcing\Rebuild\BackOfficeTaskAssignmentSplitResult;
use App\Domain\EventSourcing\Rebuild\BackOfficeTaskAssignmentSplitter;
use App\Domain\EventSourcing\Rebuild\EventStoreRebuildWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 旧形式の backoffice_task.assigned(担当者の設定と同時に暗黙で status を in_review にしていた)を、新しい構成
 * (assigned=担当者のみ + 必要なら status_changed)へ作り直す(docs/changesets/20261122-backoffice-task-reassign 論点6)。
 * 本番ではメンテナンス中にCLIで実行する(管理画面の実行口には出さない)。
 *
 * 既定は試し実行(何も書かない)。--apply で stored_events_rebuilt に書き、--verify で検査し、--swap で入れ替える(検査が通っている場合のみ)。
 */
final class RebuildBackOfficeAssignmentEventsCommand extends Command
{
    protected $signature = 'backoffice:rebuild-assignment-events
        {--apply : stored_events_rebuilt に作り直し後の行を書く(既定は試し実行)}
        {--verify : stored_events_rebuilt を検査する(連番・旧形式の assigned が0件・他の行が元と同じ・復元可否)}
        {--swap : stored_events_rebuilt と stored_events を入れ替える(検査が通っている場合のみ)}';

    protected $description = 'バックオフィスタスクの旧形式の担当者割当イベントを assigned + status_changed へ作り直す(試し実行が既定。--apply・--verify・--swap で段階的に実行)';

    public function handle(BackOfficeTaskAssignmentSplitter $splitter, BackOfficeTaskAssignmentRebuildVerifier $verifier, EventStoreRebuildWriter $writer): int
    {
        if ($this->option('swap')) {
            return $this->swap($verifier, $writer);
        }
        if ($this->option('verify') && ! $this->option('apply')) {
            return $this->verify($verifier);
        }

        $result = $splitter->split($writer->readRowArrays());
        $this->printResult($result);

        if ($result->violations !== []) {
            $this->error('変換できない並びがあるため作り直さない。');

            return self::FAILURE;
        }
        if ($result->targetCount === 0) {
            $this->info('対象なし(旧形式の assigned が無い)。何も書かない。');

            return self::SUCCESS;
        }

        $preconditions = $this->preconditionViolations();
        foreach ($preconditions as $violation) {
            $this->warn("事前条件: {$violation}");
        }
        if ($preconditions === []) {
            $this->info('事前条件: 満たしている('.EventStoreRebuildWriter::TABLE.' が無い・snapshots が空)。');
        }

        if (! $this->option('apply')) {
            $this->info('試し実行のみ(書き込みなし)。--apply で '.EventStoreRebuildWriter::TABLE.' に書く。');

            return $preconditions === [] ? self::SUCCESS : self::FAILURE;
        }
        if ($preconditions !== []) {
            $this->error('事前条件を満たしていないため書かない。');

            return self::FAILURE;
        }

        $writer->write($result->rows);
        $this->info(EventStoreRebuildWriter::TABLE.' に '.count($result->rows).' 件を書いた。');

        return $this->verify($verifier);
    }

    /** @return list<string> */
    private function preconditionViolations(): array
    {
        $violations = [];
        if (Schema::hasTable(EventStoreRebuildWriter::TABLE)) {
            $violations[] = EventStoreRebuildWriter::TABLE.' が既に存在する(前回の結果を確認して削除してから実行する)';
        }
        if (Schema::hasTable('snapshots') && DB::table('snapshots')->exists()) {
            $violations[] = 'snapshots テーブルに行がある(集約のスナップショットは作り直し後の履歴と一致しないため、削除してから実行する)';
        }

        return $violations;
    }

    private function printResult(BackOfficeTaskAssignmentSplitResult $result): void
    {
        $this->info('作り直しの件数');
        $this->line("変換する旧形式の assigned: {$result->targetCount} 件 / 挿入する status_changed: {$result->insertedStatusChanged} 件 / 変換済みでそのまま写す assigned: {$result->skippedConverted} 件");
        $this->table(['直前の status', '件数'], array_map(
            fn (string $status, int $count) => [$status, $count],
            array_keys($result->previousStatusCounts),
            array_values($result->previousStatusCounts),
        ));
        foreach ($result->terminalTransitions as $status => $uuids) {
            $this->warn("直前が {$status} の assigned(旧挙動の再現として {$status}→in_review の status_changed を挿入): ".count($uuids).' 件');
            foreach ($uuids as $uuid) {
                $this->line("  - {$uuid}");
            }
        }
        foreach ($result->violations as $violation) {
            $this->error("未対応: {$violation}");
        }
    }

    private function verify(BackOfficeTaskAssignmentRebuildVerifier $verifier): int
    {
        $problems = $verifier->verify();
        if ($problems === []) {
            $this->info(EventStoreRebuildWriter::TABLE.' の検査: 問題なし。');

            return self::SUCCESS;
        }

        $this->error('検査の違反:');
        foreach ($problems as $problem) {
            $this->line('- '.$problem);
        }

        return self::FAILURE;
    }

    private function swap(BackOfficeTaskAssignmentRebuildVerifier $verifier, EventStoreRebuildWriter $writer): int
    {
        if ($this->verify($verifier) !== self::SUCCESS) {
            $this->error('検査が通っていないため入れ替えない。');

            return self::FAILURE;
        }

        $backup = 'stored_events_before_rebuild_'.now()->format('YmdHis');
        $writer->swap($backup);
        $this->info("入れ替えた。元の表は {$backup} として残した。");

        return self::SUCCESS;
    }
}
