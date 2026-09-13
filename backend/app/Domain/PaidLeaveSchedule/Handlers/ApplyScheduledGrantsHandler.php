<?php

namespace App\Domain\PaidLeaveSchedule\Handlers;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\PaidLeaveAccount\Commands\GrantPaidLeave;
use App\Domain\PaidLeaveSchedule\Aggregates\PaidLeaveScheduleAggregate;
use App\Domain\PaidLeaveSchedule\Commands\ApplyScheduledGrants;
use Illuminate\Support\Carbon;

/**
 * 管理者の一括付与操作。対象各Scheduleエントリについて`GrantPaidLeave`を発行し、
 * Schedule側をGrantedへ遷移させる(依頼書§39)。
 * Eligible以外のエントリが混じっていた場合は当該エントリのみ失敗として扱う
 * (一括操作の他の対象を巻き込まない)。
 *
 * @implements CommandHandler<ApplyScheduledGrants>
 */
class ApplyScheduledGrantsHandler implements CommandHandler
{
    public function __construct(private readonly CommandBus $commandBus) {}

    /**
     * @return array{granted: array<int, array{scheduleEntryId: string, grantId: string}>, failed: array<int, array{scheduleEntryId: string, reason: string}>}
     */
    public function handle(Command $command): array
    {
        assert($command instanceof ApplyScheduledGrants);

        $granted = [];
        $failed = [];

        foreach ($command->scheduleEntryIds as $scheduleEntryId) {
            try {
                $aggregate = PaidLeaveScheduleAggregate::retrieve($command->userId);
                $entry = $aggregate->entry($scheduleEntryId);

                if ($entry['status'] !== PaidLeaveScheduleAggregate::STATUS_ELIGIBLE) {
                    throw new DomainRuleException("Scheduleエントリ [{$scheduleEntryId}] はEligible状態ではないため付与できません。");
                }

                $grantId = $this->commandBus->dispatch(new GrantPaidLeave(
                    userId: $command->userId,
                    grantedOn: $entry['scheduledOn'],
                    expiresOn: Carbon::parse($entry['scheduledOn'])->addYears(2)->toDateString(),
                    grantedDays: $entry['candidateGrantDays'],
                    grantReason: '付与予定Scheduleからの一括付与',
                    source: 'scheduled_batch',
                ));

                // GrantPaidLeaveは`PaidLeaveAccountAggregate::retrieve($command->userId)`を
                // 使う(=同じuserIdをaggregate_uuidとして共有する、spatie/laravel-event-sourcingの
                // stored_eventsはaggregate_uuid単位でversionを管理し、aggregate root クラスの
                // 違いを区別しない)。そのため上で保持していた`$aggregate`をそのまま使うと、
                // 直前のGrantPaidLeave発行で同じuuidのversionが進んでしまい、
                // 楽観的排他制御(CouldNotPersistAggregate)に失敗する。ここで取り直すことで
                // 最新versionを読み直してから確定させる。
                PaidLeaveScheduleAggregate::retrieve($command->userId)
                    ->grantEntry($scheduleEntryId, $grantId, $command->operatorUserId)
                    ->persist();

                $granted[] = ['scheduleEntryId' => $scheduleEntryId, 'grantId' => $grantId];
            } catch (DomainRuleException $e) {
                $failed[] = ['scheduleEntryId' => $scheduleEntryId, 'reason' => $e->getMessage()];
            }
        }

        return ['granted' => $granted, 'failed' => $failed];
    }
}
