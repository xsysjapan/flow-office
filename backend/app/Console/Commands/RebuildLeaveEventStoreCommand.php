<?php

namespace App\Console\Commands;

use App\Domain\EventSourcing\Rebuild\EventStoreRebuilder;
use App\Domain\EventSourcing\Rebuild\ReadModelTableLister;
use App\Domain\EventSourcing\Rebuild\EventStoreRebuildWriter;
use Illuminate\Console\Command;

/**
 * 休暇まわりの stored_events の作り直し(論点18、assets/event-rebuild-mapping.md 7章)。
 *
 * 既定は試し実行(何も書かない)。--apply で stored_events_rebuilt に書き、--verify で自己整合性を確かめ、
 * --swap で stored_events と入れ替える(検査が通っている場合のみ)。複製DBでの比較(手順書)は別途行う。
 */
final class RebuildLeaveEventStoreCommand extends Command
{
    protected $signature = 'leave:rebuild-event-store
        {--apply : stored_events_rebuilt に作り直し後の行を書く(既定は試し実行)}
        {--verify : stored_events_rebuilt の自己整合性(id・版の連番)を検査する}
        {--swap : stored_events_rebuilt と stored_events を入れ替える(検査が通っている場合のみ)}
        {--list-readmodels : 全Projectorの書き込み先テーブルの一覧を出す(手順書の「空にするテーブル」)}';

    protected $description = '休暇の旧イベント列を新しいイベント構成へ作り直す(試し実行が既定。--apply・--verify・--swap で段階的に実行)';

    public function handle(EventStoreRebuilder $rebuilder, EventStoreRebuildWriter $writer): int
    {
        if ($this->option('list-readmodels')) {
            $tables = (new ReadModelTableLister)->tables(app_path('Domain'));
            $this->table(['テーブル', '参照元のProjector'], array_map(
                fn (string $table, array $origins) => [$table, implode(', ', $origins)],
                array_keys($tables),
                array_values($tables),
            ));

            return self::SUCCESS;
        }

        $violations = $writer->preconditionViolations();
        foreach ($violations as $violation) {
            $this->warn('事前条件: '.$violation);
        }

        if ($this->option('swap')) {
            return $this->swap($writer);
        }
        if ($this->option('verify') && ! $this->option('apply')) {
            return $this->verifyOnly($writer);
        }

        $result = $rebuilder->rebuild($writer->readRows(), $writer->readAttendanceDays());
        $this->printReport($result->report);

        if (! $result->ok) {
            $this->error('扱えない並びがあるため作り直せない(推測で埋めない。変換規則を決めてから再実行する)。');
            $this->table(['対象', '理由'], array_map(fn (array $u) => [$u['key'], $u['reason']], $result->unsupported));

            return self::FAILURE;
        }

        if (! $this->option('apply')) {
            $this->info('試し実行のみ(書き込みなし)。--apply で '.EventStoreRebuildWriter::TABLE.' に書く。');

            return $violations === [] ? self::SUCCESS : self::FAILURE;
        }

        if ($violations !== []) {
            $this->error('事前条件を満たしていないため --apply できない。');

            return self::FAILURE;
        }

        $writer->write($result->rows);
        $this->info(EventStoreRebuildWriter::TABLE.' に '.count($result->rows).' 件を書いた。');

        return $this->verifyOnly($writer);
    }

    private function verifyOnly(EventStoreRebuildWriter $writer): int
    {
        $problems = $writer->verify();
        if ($problems === []) {
            $this->info(EventStoreRebuildWriter::TABLE.' の自己整合性: 問題なし。');

            return self::SUCCESS;
        }
        $this->error('自己整合性の違反:');
        foreach ($problems as $problem) {
            $this->line('- '.$problem);
        }

        return self::FAILURE;
    }

    private function swap(EventStoreRebuildWriter $writer): int
    {
        if ($this->verifyOnly($writer) !== self::SUCCESS) {
            $this->error('検査が通っていないため入れ替えない。');

            return self::FAILURE;
        }

        $backup = 'stored_events_before_rebuild_'.now()->format('YmdHis');
        $writer->swap($backup);
        $this->info("入れ替えた。元の表は {$backup} として残した。");

        return self::SUCCESS;
    }

    /** @param  array<string, mixed>  $report */
    private function printReport(array $report): void
    {
        $this->info('作り直しの件数(変換前)');
        $this->table(['event_class', '件数'], $this->countRows($report['by_class_before'] ?? []));

        if (isset($report['by_class_after'])) {
            $this->info('作り直しの件数(変換後)');
            $this->table(['event_class', '件数'], $this->countRows($report['by_class_after']));
        }

        $this->info('申請(有給)ごとの最終状態');
        $requests = $report['paid_requests'] ?? [];
        $states = [];
        foreach ($requests as $request) {
            $states[$request['final_state']] = ($states[$request['final_state']] ?? 0) + 1;
        }
        $this->table(['最終状態', '件数'], $this->countRows($states));
        $this->line('再提出の回数が1以上の申請: '.count(array_filter($requests, fn (array $r) => $r['resubmit_count'] > 0)).' 件');

        foreach (['special_requests' => '特別休暇', 'compensatory_requests' => '代休'] as $reportKey => $label) {
            $leaveRequests = $report[$reportKey] ?? [];
            $leaveStates = [];
            foreach ($leaveRequests as $request) {
                $leaveStates[$request['final_state']] = ($leaveStates[$request['final_state']] ?? 0) + 1;
            }
            $this->info("申請({$label})ごとの最終状態");
            $this->table(['最終状態', '件数'], $this->countRows($leaveStates));
        }
        foreach ($report['leave_grants'] ?? [] as $kind => $count) {
            $this->line("付与の変換({$kind}): {$count} 件");
        }

        if (isset($report['attendance'])) {
            $attendance = $report['attendance'];
            $this->info('勤怠日の変換');
            $this->line("対象の勤怠日: {$attendance['days']} 件 / created を補った日: {$attendance['created_added']} 件 / deleted を補った日: {$attendance['deleted_added']} 件");
            $this->line("休暇値の workType を書き換えた件数: {$attendance['work_type_rewritten']} / 既定値nullの項目を補完した件数: {$attendance['null_items_filled']}");
        }

        $this->info('cutoverの付与の突き合わせ結果');
        $cutover = $report['cutover'] ?? [];
        $outcomes = [];
        foreach ($cutover as $row) {
            $outcomes[$row['outcome']] = ($outcomes[$row['outcome']] ?? 0) + 1;
        }
        $this->table(['結果', '件数'], $this->countRows($outcomes));

        if (isset($report['versions_changed_outside_leave'])) {
            $this->line('休暇以外の集約で版が変わった件数: '.$report['versions_changed_outside_leave']);
        }
    }

    /**
     * @param  array<string, int>  $counts
     * @return list<array{string, int}>
     */
    private function countRows(array $counts): array
    {
        $rows = [];
        foreach ($counts as $key => $count) {
            $rows[] = [(string) $key, $count];
        }

        return $rows;
    }
}
