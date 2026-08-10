<?php

namespace App\Console\Commands;

use App\Domain\EventSourcing\Migration\StoredEventHistoryNormalizer;
use Illuminate\Console\Command;

final class NormalizeStoredEventHistoryCommand extends Command
{
    protected $signature = 'events:normalize-history
        {--apply : Create backups and apply the normalization}
        {--backup-table=stored_events_backup_20260810 : Immutable backup table name}';

    protected $description = 'Inspect or normalize legacy StoredEvents into the current user/group and workflow event flows';

    public function handle(StoredEventHistoryNormalizer $normalizer): int
    {
        $before = $normalizer->inspect();
        $this->table(['check', 'value'], $this->rows($before));

        if (! $this->option('apply')) {
            $this->info('Dry-run only. Re-run with --apply after verifying the database backup.');

            return self::SUCCESS;
        }

        $after = $normalizer->apply((string) $this->option('backup-table'));
        $this->info('StoredEvent history normalization completed.');
        $this->table(['check after normalization', 'value'], $this->rows($after));

        return self::SUCCESS;
    }

    /** @param array<string, int|list<string>> $report @return list<array{string,string}> */
    private function rows(array $report): array
    {
        return collect($report)->map(fn ($value, $key) => [
            (string) $key,
            is_array($value) ? ($value === [] ? '-' : implode(', ', $value)) : (string) $value,
        ])->values()->all();
    }
}
