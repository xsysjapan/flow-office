<?php

namespace App\Console\Commands;

use App\Domain\EventSourcing\HistoryEventTypesExporter;
use Illuminate\Console\Command;

/**
 * 履歴画面に出るイベント種別の一覧を frontend/src/generated/historyEventTypes.ts へ書き出す。
 * イベントを足したらこのコマンドで再生成し、frontend/src/utils/statusLabels.ts の表示名・詳細文を足す。
 * `--check` は書き出さずに比較し、生成ファイルが古ければ失敗する(PHPUnitで同じ比較を行う)。
 */
class ExportHistoryEventTypesCommand extends Command
{
    protected $signature = 'events:export-history-types {--check}';

    protected $description = '履歴画面に出るイベント種別の一覧をfrontendの生成ファイルへ書き出す';

    public function handle(HistoryEventTypesExporter $exporter): int
    {
        $content = $exporter->render();
        $path = HistoryEventTypesExporter::outputPath();

        if ($this->option('check')) {
            $current = is_file($path) ? file_get_contents($path) : null;
            if ($current !== $content) {
                $this->error('frontend/src/generated/historyEventTypes.ts が古い。`php artisan events:export-history-types` を実行してコミットしてください');

                return self::FAILURE;
            }

            $this->info('frontend/src/generated/historyEventTypes.ts は最新です。');

            return self::SUCCESS;
        }

        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($path, $content);
        $this->info("書き出しました: {$path}");

        return self::SUCCESS;
    }
}
