<?php

namespace App\Console\Commands;

use App\Domain\EventSourcing\Rebuild\EventRestorabilityChecker;
use App\Domain\EventSourcing\Rebuild\EventStoreRebuilder;
use App\Domain\EventSourcing\Rebuild\ReadModelDiffer;
use App\Domain\EventSourcing\Rebuild\ReadModelFingerprinter;
use App\Domain\EventSourcing\Rebuild\ReadModelTableLister;
use App\Domain\EventSourcing\Rebuild\EventStoreRebuildWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

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
        {--verify : stored_events_rebuilt の自己整合性(id・版の連番)と、現行のイベントクラスへの復元可否を検査する}
        {--swap : stored_events_rebuilt と stored_events を入れ替える(検査が通っている場合のみ)}
        {--list-readmodels : 全Projectorの書き込み先テーブルの一覧を出す(手順書の「空にするテーブル」)}
        {--readmodel-fingerprint : 全ReadModelのテーブルごとに「件数」と「行の内容のハッシュ」を出す(作り直し前後のDBをdiffで比べる。読み取りのみ)}
        {--readmodel-fingerprint-rows= : 指定したテーブルの正規化した行(ソート済み)を出す(--readmodel-fingerprint の差分の行を特定する)}
        {--readmodel-diff= : 全ReadModelのテーブルごとに、現在のDBと、同じサーバー上の指定したデータベース(作り直し前)の行単位の差を出す。読み取りのみ}
        {--readmodel-diff-json= : --readmodel-diff の全テーブル・全件の差の要約と例をJSONで保存するファイル}';

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

        if ($this->option('readmodel-diff')) {
            return $this->readModelDiff((string) $this->option('readmodel-diff'), $this->option('readmodel-diff-json'));
        }
        if ($this->option('readmodel-fingerprint-rows')) {
            return $this->fingerprintRows((string) $this->option('readmodel-fingerprint-rows'));
        }
        if ($this->option('readmodel-fingerprint')) {
            return $this->fingerprint();
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

        $result = $rebuilder->rebuild($writer->readRows(), $writer->readAttendanceDays(), $writer->readPaidLeaveSchedule(), $writer->readSeededData(), $writer->readCalendarDayIndex());
        $this->printReport($result->report);

        if (! $result->ok) {
            $this->error('扱えない並びがあるため作り直せない(推測で埋めない。変換規則を決めてから再実行する)。');
            $this->table(['対象', '理由'], array_map(fn (array $u) => [$u['key'], $u['reason']], $result->unsupported));

            return self::FAILURE;
        }

        // 作り直し後に出力されるイベント列(メモリ上の結果)を現行のイベントクラスへ復元できるか検査する。
        $restoreFailures = (new EventRestorabilityChecker)->checkRows($result->rows);
        $this->printRestorability('作り直し後のイベント列(メモリ上)', $restoreFailures);

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

    /** 1行1テーブル: テーブル名<TAB>件数<TAB>ハッシュ(テーブル名順。diffで比べる用なので表整形はしない)。 */
    private function fingerprint(): int
    {
        $fingerprinter = new ReadModelFingerprinter;
        foreach (array_keys((new ReadModelTableLister)->tables(app_path('Domain'))) as $table) {
            $result = $fingerprinter->fingerprint($table);
            $this->line($result === null ? "{$table}\t-\t(テーブルなし)" : "{$table}\t{$result['count']}\t{$result['hash']}");
        }

        return self::SUCCESS;
    }

    /** 現在の接続の設定を複製して database だけ差し替えた接続を実行時に作り、2つのDBの差を出す(どちらにも書かない)。 */
    private function readModelDiff(string $database, mixed $jsonPath): int
    {
        $default = (string) config('database.default');
        $settings = config("database.connections.{$default}");
        $settings['database'] = $database;
        $otherConnection = 'readmodel_diff_other';
        config(["database.connections.{$otherConnection}" => $settings]);

        $tables = array_keys((new ReadModelTableLister)->tables(app_path('Domain')));
        $diff = (new ReadModelDiffer($default, $otherConnection))->diff($tables);

        $this->table(['テーブル', '件数(比較先)', '件数(現在)', '比較先のみ', '現在のみ', '内容が違う', '備考'], ReadModelDiffer::summaryRows($diff));

        foreach ($diff as $table => $d) {
            if ($d['status'] !== 'compared') {
                continue;
            }
            $hasDiff = $d['only_other'] > 0 || $d['only_current'] > 0 || $d['changed'] > 0;
            if (! $hasDiff && $d['columns_only_current'] === [] && $d['columns_only_other'] === []) {
                continue;
            }
            $this->info("[{$table}]");
            if ($d['columns_only_current'] !== [] || $d['columns_only_other'] !== []) {
                $this->line('片側にしかない列(比較から除く) 現在のみ: '.implode(', ', $d['columns_only_current']).' / 比較先のみ: '.implode(', ', $d['columns_only_other']));
            }
            if ($d['changed_columns'] !== []) {
                $this->table(['違う列', '件数'], array_map(fn ($c, $n) => [$c, $n], array_keys($d['changed_columns']), array_values($d['changed_columns'])));
                $this->table(['キー', '列', '比較先の値', '現在の値', '差(秒)'], array_map(
                    fn (array $e) => [$e['key'], $e['column'], $e['other'], $e['current'], isset($e['diff_seconds']) ? sprintf('%+d', $e['diff_seconds']) : '-'],
                    $d['changed_examples'],
                ));
            }
            foreach ($d['datetime_diff_seconds'] as $column => $counts) {
                $this->line("日時の差(秒) {$column}: ".implode(' / ', array_map(fn ($sec, $n) => "{$sec}: {$n}件", array_keys($counts), array_values($counts))));
            }
            foreach (['only_other_examples' => '比較先にだけある行', 'only_current_examples' => '現在にだけある行'] as $field => $label) {
                foreach ($d[$field] as $example) {
                    $this->line("{$label}: ".json_encode($example, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }
            }
        }

        if (is_string($jsonPath) && $jsonPath !== '') {
            file_put_contents($jsonPath, json_encode(
                ['current_database' => config("database.connections.{$default}.database"), 'other_database' => $database, 'tables' => $diff],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));
            $this->info("JSON を {$jsonPath} に保存した。");
        }

        return self::SUCCESS;
    }

    private function fingerprintRows(string $table): int
    {
        if (! array_key_exists($table, (new ReadModelTableLister)->tables(app_path('Domain')))) {
            $this->error("{$table} は --list-readmodels の一覧にない。");

            return self::FAILURE;
        }
        $rows = (new ReadModelFingerprinter)->rows($table);
        if ($rows === null) {
            $this->error("{$table} のテーブルが無い。");

            return self::FAILURE;
        }
        foreach ($rows as $row) {
            $this->line($row);
        }

        return self::SUCCESS;
    }

    private function verifyOnly(EventStoreRebuildWriter $writer): int
    {
        $problems = $writer->verify();
        $ok = true;
        if ($problems === []) {
            $this->info(EventStoreRebuildWriter::TABLE.' の自己整合性: 問題なし。');
        } else {
            $ok = false;
            $this->error('自己整合性の違反:');
            foreach ($problems as $problem) {
                $this->line('- '.$problem);
            }
        }

        if (Schema::hasTable(EventStoreRebuildWriter::TABLE)) {
            $failures = (new EventRestorabilityChecker)->checkTable(EventStoreRebuildWriter::TABLE);
            $ok = $this->printRestorability(EventStoreRebuildWriter::TABLE, $failures) && $ok;
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * 復元できない行の一覧を出す。1件も無ければ true。
     *
     * @param  array<string, array{count: int, first_id: int, message: string}>  $failures
     */
    private function printRestorability(string $target, array $failures): bool
    {
        if ($failures === []) {
            $this->info("{$target} の復元可否: 全行を現行のイベントクラスへ復元できる。");

            return true;
        }

        $this->error("{$target} の復元可否: 現行のイベントクラスへ復元できない行がある。");
        $this->table(['event_class', '件数', '先頭のid', '最初の例外メッセージ'], array_map(
            fn (string $class, array $f) => [$class, $f['count'], $f['first_id'], $f['message']],
            array_keys($failures),
            array_values($failures),
        ));

        return false;
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
            $this->line('全休の日の edited の status(clocked_out・実績null)を直前の状態に書き換えた件数: '.($attendance['status_rewritten'] ?? 0));
            foreach ($attendance['status_rewritten_targets'] ?? [] as $target) {
                $this->line("  勤怠日id={$target['dayId']} 勤務日={$target['workDate']} イベントid={$target['eventId']}");
            }
        }

        if (isset($report['holiday_calendar_day_refs'])) {
            $refs = $report['holiday_calendar_day_refs'];
            $this->info('祝日の同期イベントへの companyCalendarYearId・date の補完');
            $this->line("補った要素: {$refs['filled']} 件 / 補えなかった要素(対応する日の行が無い): {$refs['unresolved']} 件 / 既に揃っていた要素: {$refs['already_complete']} 件 / 置き換えたイベント: {$refs['events']} 件");
        }

        if (isset($report['reconcile'])) {
            $reconcile = $report['reconcile'];
            $this->info('照合による補完(イベントを経ずに作られた行・最終状態がずれた行の差を埋める導出イベント)');
            $derivedRows = [];
            foreach ($reconcile['derived'] as $table => $classes) {
                foreach ($classes as $class => $count) {
                    $derivedRows[] = [$table, $class, $count];
                }
            }
            $this->table(['テーブル', '導出したevent_class', '件数'], $derivedRows);
            $this->line('テーブルごとの導出イベント件数: '.($reconcile['derived'] === [] ? 'なし' : implode(' / ', array_map(
                fn (string $table, array $classes) => $table.' '.array_sum($classes),
                array_keys($reconcile['derived']),
                array_values($reconcile['derived']),
            ))));
            $mismatchRows = [];
            foreach ($reconcile['mismatches'] as $table => $keys) {
                foreach ($keys as $key) {
                    $mismatchRows[] = [$table, $key];
                }
            }
            $this->line('イベントがあるのに最終状態が違ったキー: '.count($mismatchRows).' 件');
            if ($mismatchRows !== []) {
                $this->table(['テーブル', 'キー: 違い'], $mismatchRows);
            }
            if ($reconcile['remapped'] !== []) {
                $this->line('集約UUIDを付け替えた company_calendar_year.days_updated: '.count($reconcile['remapped']).' 件');
                $this->table(['stored_events.id', '旧集約UUID(company_calendars.id)', '新集約UUID(年のid)'], array_map(
                    fn (array $r) => [$r['id'], $r['from'], $r['to']],
                    $reconcile['remapped'],
                ));
            }
        }

        if (isset($report['paid_leave_schedule'])) {
            $schedule = $report['paid_leave_schedule'];
            $this->info('旧版の付与予定イベントの置き換え');
            $this->line("対象の付与予定(entryId): {$schedule['entries']} 件");
            $this->info('置き換えた旧版のイベント');
            $this->table(['event_class', '件数'], $this->countRows($schedule['replaced']));
            $this->info('導出した現行のイベント');
            $this->table(['event_class', '件数'], $this->countRows($schedule['derived']));
        }

        $this->info('cutoverの付与の突き合わせ結果');
        $cutover = $report['cutover'] ?? [];
        $outcomes = [];
        foreach ($cutover as $row) {
            $outcomes[$row['outcome']] = ($outcomes[$row['outcome']] ?? 0) + 1;
        }
        $this->table(['結果', '件数'], $this->countRows($outcomes));

        if (isset($report['usage_allocation'])) {
            $allocation = $report['usage_allocation'];
            $this->info('未充当の充当(決定10-1。確定時: 確定の時点の付与へ充当 / 付与時: 付与の時点で不足している消化記録へ確定の古い順に充当)');
            $this->line("充当した消化記録: {$allocation['allocated_usages']} 件 / {$allocation['allocated_days']} 日");
            $this->line("未充当のまま(付与なし・付与の残り不足): {$allocation['unallocated_usages']} 件 / {$allocation['unallocated_days']} 日");
            if (($allocation['usages'] ?? []) !== []) {
                $this->table(['消化記録', '利用者', '対象日', '日数', '充当した付与と日数', '未充当の日数', '理由'], array_map(
                    fn (array $r) => [
                        $r['usage_id'], $r['user_id'], $r['used_on'], $r['days'],
                        implode(', ', array_map(fn (array $a) => "{$a['grant_id']}: {$a['days']} ({$a['rule']})", $r['allocations'])) ?: '-',
                        $r['unallocated_days'], $r['reason'] ?? '-',
                    ],
                    $allocation['usages'],
                ));
            }
        }

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
