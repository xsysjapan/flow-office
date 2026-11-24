<?php

namespace App\Console\Commands;

use App\Domain\EventSourcing\Rebuild\LegacyBooleanPropertyDetector;
use App\Domain\EventSourcing\Rebuild\LegacyCalendarBulkOperationTargetIdDetector;
use App\Domain\EventSourcing\Rebuild\LegacyUserSettingsDetector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Spatie\EventSourcing\Projectionist;

/** Backward-compatible entry point backed by Spatie's replay command. */
final class RebuildProjectionsCommand extends Command
{
    protected $signature = 'projections:rebuild {projector? : Projector short or fully-qualified class name}';

    protected $description = 'Replay stored_events to Spatie projectors';

    public function handle(Projectionist $projectionist, LegacyBooleanPropertyDetector $booleanDetector, LegacyCalendarBulkOperationTargetIdDetector $bulkTargetIdDetector, LegacyUserSettingsDetector $userSettingsDetector): int
    {
        // 旧項目名の真偽値のイベントが残っている間は、再生すると読めない(または既定値に化ける)ため拒否する。
        $booleanCount = $booleanDetector->count();
        if ($booleanCount > 0) {
            $this->error("旧項目名の真偽値のイベントが{$booleanCount}件あります。先に運用コマンド『真偽値のイベント項目名の書き換え』(events:rename-boolean-properties)を実行してください。");

            return self::FAILURE;
        }

        // タイムゾーン・テナントIDが記録されていない(未補正の)利用者のイベントが残っている間は、再生が例外になる(または値が変わる)ため拒否する。
        $userSettingsCount = $userSettingsDetector->count();
        if ($userSettingsCount > 0) {
            $this->error(LegacyUserSettingsDetector::message($userSettingsCount));

            return self::FAILURE;
        }

        // 対象行のidが旧い体系の一括操作の取消イベントが残っている間は、再生すると旧idの行が消えて対応表を作れなくなるため拒否する。
        $bulkCount = $bulkTargetIdDetector->count();
        if ($bulkCount > 0) {
            $this->error(LegacyCalendarBulkOperationTargetIdDetector::message($bulkCount));

            return self::FAILURE;
        }

        $requested = $this->argument('projector');
        $arguments = ['--force' => true];

        if ($requested !== null) {
            $projector = $projectionist->getProjectors()->first(
                fn ($projector) => get_class($projector) === ltrim($requested, '\\')
                    || Str::afterLast(get_class($projector), '\\') === $requested,
            );
            if ($projector === null) {
                $this->error("Projector [{$requested}] is not registered.");

                return self::FAILURE;
            }
            $arguments['projector'] = [get_class($projector)];
        }

        $exitCode = Artisan::call('event-sourcing:replay', $arguments, $this->output);

        return $exitCode === 0 ? self::SUCCESS : self::FAILURE;
    }
}
