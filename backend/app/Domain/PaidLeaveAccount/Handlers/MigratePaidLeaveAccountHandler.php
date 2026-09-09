<?php

namespace App\Domain\PaidLeaveAccount\Handlers;

use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\PaidLeaveAccount\Aggregates\PaidLeaveAccountAggregate;
use App\Domain\PaidLeaveAccount\Commands\MigratePaidLeaveAccount;
use Illuminate\Support\Str;

/**
 * @implements CommandHandler<MigratePaidLeaveAccount>
 */
class MigratePaidLeaveAccountHandler implements CommandHandler
{
    public function handle(Command $command): mixed
    {
        assert($command instanceof MigratePaidLeaveAccount);

        $grants = array_map(
            static fn (array $g): array => [
                'grantId' => $g['grantId'] ?? (string) Str::uuid(),
                'originalGrantedOn' => $g['originalGrantedOn'] ?? null,
                'originalGrantedDays' => isset($g['originalGrantedDays']) ? (float) $g['originalGrantedDays'] : null,
                'remainingDaysAtCutover' => (float) $g['remainingDaysAtCutover'],
                'expiresOn' => $g['expiresOn'],
                // grant_reason/source列で移行由来のGrantを監査上区別する
                // (spec.md 論点/§47)。3モードの区別自体はcutoverMetadataへ記録する。
                'source' => 'migration',
                'cutoverMetadata' => [
                    'mode' => $g['mode'],
                    'notes' => $g['notes'] ?? null,
                ],
            ],
            $command->grants,
        );

        PaidLeaveAccountAggregate::retrieve($command->userId)
            ->migrateGrants($command->cutoverDate, $grants)
            ->persist();

        return null;
    }
}
