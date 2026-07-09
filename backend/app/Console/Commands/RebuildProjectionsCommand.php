<?php

namespace App\Console\Commands;

use App\Domain\EventSourcing\Contracts\Projector;
use App\Models\StoredEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Projection Table を stored_events から再生成する。
 * docs/03-architecture.md 3.2節、.claude/skills/add-projection 参照。
 */
class RebuildProjectionsCommand extends Command
{
    protected $signature = 'projections:rebuild {projector? : Projectorクラスの短縮名 (例: AttendanceDailyCalculationProjector)}';

    protected $description = 'stored_events からProjection Tableを再生成する';

    public function handle(): int
    {
        $target = $this->argument('projector');
        $projectorClasses = collect(config('domain.projectors', []));

        if ($target !== null) {
            $projectorClasses = $projectorClasses->filter(
                fn (string $class) => Str::afterLast($class, '\\') === $target
            );

            if ($projectorClasses->isEmpty()) {
                $this->error("Projector [{$target}] は config/domain.php に登録されていません。");

                return self::FAILURE;
            }
        }

        foreach ($projectorClasses as $projectorClass) {
            /** @var Projector $projector */
            $projector = app($projectorClass);
            $this->info("Rebuilding {$projectorClass} ...");
            $projector->reset();

            $count = 0;
            StoredEvent::query()
                ->whereIn('event_type', $projector->eventTypes())
                ->orderBy('occurred_at')
                ->orderBy('id')
                ->chunkById(500, function ($events) use ($projector, &$count) {
                    foreach ($events as $event) {
                        $projector->project($event);
                        $count++;
                    }
                });

            $this->info("  -> {$count} 件のイベントを反映しました。");
        }

        return self::SUCCESS;
    }
}
