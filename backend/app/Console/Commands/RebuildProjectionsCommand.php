<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Spatie\EventSourcing\Projectionist;

/** Backward-compatible entry point backed by Spatie's replay command. */
final class RebuildProjectionsCommand extends Command
{
    protected $signature = 'projections:rebuild {projector? : Projector short or fully-qualified class name}';

    protected $description = 'Replay stored_events to Spatie projectors';

    public function handle(Projectionist $projectionist): int
    {
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
