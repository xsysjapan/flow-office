<?php

namespace App\Domain\PaidLeaveSchedule\Handlers;

use App\Domain\EventSourcing\BulkItemRunner;
use App\Domain\EventSourcing\BulkOperationRejected;
use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\PaidLeaveSchedule\Commands\ApplyScheduledGrants;
use App\Domain\PaidLeaveSchedule\Commands\ApplyScheduledGrantsInBulk;
use App\Models\PaidLeaveScheduleEntry;

/**
 * 利用者ごとに`ApplyScheduledGrants`をdispatchし(各利用者はセーブポイントの中)、失敗した行を集める。
 * 1行でも失敗があれば`BulkOperationRejected`を投げて全体を戻す(部分成功にしない)。
 * 集める失敗は、付与の前の検査の失敗(`ApplyScheduledGrants`の`failed`)と`DomainRuleException`だけ。
 *
 * @implements CommandHandler<ApplyScheduledGrantsInBulk>
 */
class ApplyScheduledGrantsInBulkHandler implements CommandHandler
{
    public function __construct(private readonly CommandBus $commandBus) {}

    /**
     * @return array{granted: list<array{scheduleEntryId: string, grantId: string}>}
     */
    public function handle(Command $command): array
    {
        assert($command instanceof ApplyScheduledGrantsInBulk);

        $entryIds = array_map('strval', BulkItemRunner::sortIds($command->scheduleEntryIds));

        /** @var array<string, string> $userIdByEntry */
        $userIdByEntry = PaidLeaveScheduleEntry::query()
            ->whereIn('id', $entryIds)
            ->pluck('user_id', 'id')
            ->map(fn ($userId) => (string) $userId)
            ->all();

        $failures = [];
        $byUser = [];
        foreach ($entryIds as $entryId) {
            if (! isset($userIdByEntry[$entryId])) {
                $failures[] = ['id' => $entryId, 'message' => '対象が見つかりません。'];

                continue;
            }
            $byUser[$userIdByEntry[$entryId]][] = $entryId;
        }
        ksort($byUser);

        $granted = [];
        foreach ($byUser as $userId => $userEntryIds) {
            try {
                $outcome = $this->commandBus->dispatch(new ApplyScheduledGrants(
                    userId: (string) $userId,
                    scheduleEntryIds: $userEntryIds,
                    operatorUserId: $command->operatorUserId,
                ));
            } catch (DomainRuleException $e) {
                foreach ($userEntryIds as $entryId) {
                    $failures[] = ['id' => $entryId, 'message' => $e->getMessage()];
                }

                continue;
            }

            foreach ($outcome['granted'] as $item) {
                $granted[] = $item;
            }
            foreach ($outcome['failed'] as $item) {
                $failures[] = ['id' => $item['scheduleEntryId'], 'message' => $item['reason']];
            }
        }

        if ($failures !== []) {
            throw new BulkOperationRejected($failures);
        }

        return ['granted' => $granted];
    }
}
