<?php

namespace App\Console\Commands;

use App\Domain\EventSourcing\Migration\AttendanceBackOfficeTaskHistoryBackfiller;
use Illuminate\Console\Command;

final class BackfillAttendanceBackOfficeTaskHistoryCommand extends Command
{
    protected $signature = 'events:backfill-attendance-backoffice-tasks
        {--apply : Create the missing current-flow events and projections}';

    protected $description = 'Backfill backoffice_task.created after historical attendance-month approvals';

    public function handle(AttendanceBackOfficeTaskHistoryBackfiller $backfiller): int
    {
        $missing = $backfiller->countMissing();
        $this->line("Approved attendance months without a back-office task: {$missing}");

        if (! $this->option('apply')) {
            $this->info('Dry-run only. Re-run with --apply to append the missing events.');

            return self::SUCCESS;
        }

        $created = $backfiller->apply();
        $this->info("Created {$created} back-office task event(s).");

        return self::SUCCESS;
    }
}
