<?php

namespace App\Console\Commands;

use App\Domain\Leave\Correction\LeaveCorrectionCandidateDetector;
use Illuminate\Console\Command;

/**
 * 休暇の本番データ補正の候補(変更セット論点12の(1)〜(4))を検出して一覧する。試し実行専用で、何も変更しない。
 *
 * 各候補に採りうる方法(直接修正/補正イベント)と推奨・根拠を出力する。リハーサルでこのコマンドを流して件数を確認し、
 * 方法を決めてユーザーの許可を得てから、補正用コマンド(StoredEventCorrectionCommand の子クラス・補正Command)で本実行する。
 */
class LeaveCorrectionReportCommand extends Command
{
    protected $signature = 'leave:correction-report
        {--limit=20 : 候補ごとに一覧へ出す件数の上限}';

    protected $description = '休暇の補正候補(差戻し後の未取消の消化記録・直接作られた勤怠日・編集イベントの休暇値・勤怠日に残る休暇値)を検出する(何も変更しない)';

    public function handle(LeaveCorrectionCandidateDetector $detector): int
    {
        $limit = max(0, (int) $this->option('limit'));
        $this->info('休暇の補正候補(試し実行。何も変更しません)');

        foreach ($detector->detect() as $candidate) {
            $this->newLine();
            $this->line("<options=bold>{$candidate['title']}: {$candidate['count']}件</>");

            $this->line('  採りうる方法:');
            foreach ($candidate['methods'] as $method) {
                $mark = $method['recommended'] ? '[推奨]' : '[不採用候補]';
                $this->line("    {$mark} {$method['name']}");
                $this->line("        根拠: {$method['rationale']}");
            }

            if ($candidate['items'] !== []) {
                $this->line('  一覧(先頭'.min($limit, count($candidate['items'])).'件):');
                $rows = array_slice($candidate['items'], 0, $limit);
                foreach ($rows as $item) {
                    $this->line('    '.implode(' | ', array_map(
                        fn ($key, $value) => $key.'='.(is_bool($value) ? ($value ? 'true' : 'false') : (string) ($value ?? '-')),
                        array_keys($item),
                        array_values($item),
                    )));
                }
            }
        }

        return self::SUCCESS;
    }
}
