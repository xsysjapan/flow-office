<?php

namespace App\Console\Commands;

use App\Domain\EventSourcing\Rebuild\EventStoreRebuildWriter;
use App\Domain\EventSourcing\Rebuild\PaidLeaveRekeyResult;
use App\Domain\EventSourcing\Rebuild\PaidLeaveStreamRekeyer;
use App\Domain\EventSourcing\Rebuild\PaidLeaveStreamRekeyVerifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * 作り直し済みの stored_events について、有給口座(paid_leave_account.*)を新しいストリームIDへ付け替え、付与予定
 * (paid_leave_schedule.*)の行を取り除く(docs/changesets/20261021-paid-leave-aggregate-stream-id 論点3、
 * docs/32-stored-event-history-normalization.md)。本番ではメンテナンス中にCLIで実行する(管理画面の実行口には出さない)。
 *
 * 既定は試し実行(何も書かない)。--apply で stored_events_rebuilt に書き、--verify で検査し、--swap で入れ替える(検査が通っている場合のみ)。
 * ReadModelの指紋の比較は leave:rebuild-event-store の --readmodel-fingerprint・--readmodel-diff を使う。
 */
final class RekeyPaidLeaveStreamsCommand extends Command
{
    protected $signature = 'leave:rekey-paid-leave-streams
        {--apply : stored_events_rebuilt に付け替え後の行を書く(既定は試し実行)}
        {--verify : stored_events_rebuilt を検査する(社員のストリームは user.* だけ・有給口座のID・付与予定が0件・他の行が元と同じ・復元可否)}
        {--swap : stored_events_rebuilt と stored_events を入れ替える(検査が通っている場合のみ)}';

    protected $description = '有給口座のイベントを新しいストリームIDへ付け替え、付与予定のイベントを取り除く(試し実行が既定。--apply・--verify・--swap で段階的に実行)';

    public function handle(PaidLeaveStreamRekeyer $rekeyer, PaidLeaveStreamRekeyVerifier $verifier, EventStoreRebuildWriter $writer): int
    {
        if ($this->option('swap')) {
            return $this->swap($verifier, $writer);
        }
        if ($this->option('verify') && ! $this->option('apply')) {
            return $this->verify($verifier);
        }

        $result = $rekeyer->rekey($writer->readRowArrays());
        $this->printResult($result);

        if (! $result->applied) {
            $this->error('事前条件を満たしていないため付け替えない(付与予定・一括付与が使われていた形跡がある)。');

            return self::FAILURE;
        }
        if (! $this->option('apply')) {
            $this->info('試し実行のみ(書き込みなし)。--apply で '.EventStoreRebuildWriter::TABLE.' に書く。');

            return self::SUCCESS;
        }
        if (Schema::hasTable(EventStoreRebuildWriter::TABLE)) {
            $this->error(EventStoreRebuildWriter::TABLE.' が既に存在する(前回の結果を確認して削除してから実行する)。');

            return self::FAILURE;
        }

        $writer->write($result->rows);
        $this->info(EventStoreRebuildWriter::TABLE.' に '.count($result->rows).' 件を書いた。');

        return $this->verify($verifier);
    }

    private function printResult(PaidLeaveRekeyResult $result): void
    {
        $this->info('付け替えの件数');
        $this->line("付け替える有給口座の行: {$result->rekeyedRows} 件 / 旧ID→新IDの対応: ".count($result->mapping).' 件 / 版を振り直すストリーム: '.$result->renumberedStreams." 件(版が変わる行 {$result->renumberedRows} 件)");
        $this->line("取り除く付与予定の行: {$result->removedRows()} 件");
        $this->table(['event_class', '件数'], array_map(
            fn (string $class, int $count) => [$class, $count],
            array_keys($result->removedByClass),
            array_values($result->removedByClass),
        ));

        if ($result->violations === []) {
            $this->info('事前条件: 満たしている(付与予定の付与済み・判定の上書き・個別修正、source=scheduled_batch の付与が無い)。');

            return;
        }
        foreach ($result->violations as $description => $count) {
            $this->warn("事前条件: {$description}: {$count} 件");
        }
    }

    private function verify(PaidLeaveStreamRekeyVerifier $verifier): int
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

    private function swap(PaidLeaveStreamRekeyVerifier $verifier, EventStoreRebuildWriter $writer): int
    {
        if ($this->verify($verifier) !== self::SUCCESS) {
            $this->error('検査が通っていないため入れ替えない。');

            return self::FAILURE;
        }

        $backup = 'stored_events_before_rekey_'.now()->format('YmdHis');
        $writer->swap($backup);
        $this->info("入れ替えた。元の表は {$backup} として残した。");

        return self::SUCCESS;
    }
}
