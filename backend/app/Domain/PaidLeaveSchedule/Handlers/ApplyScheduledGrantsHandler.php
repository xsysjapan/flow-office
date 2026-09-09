<?php

namespace App\Domain\PaidLeaveSchedule\Handlers;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\PaidLeaveAccount\Commands\GrantPaidLeave;
use App\Domain\PaidLeaveSchedule\Aggregates\PaidLeaveScheduleAggregate;
use App\Domain\PaidLeaveSchedule\Commands\ApplyScheduledGrants;
use App\Domain\PaidLeaveSchedule\Support\ScheduleEntryStatus;
use Illuminate\Support\Carbon;

/**
 * 管理者の一括付与操作(依頼書§39、spec.md論点8)。Eligibleエントリについてのみ、
 * `App\Domain\PaidLeaveAccount\Commands\GrantPaidLeave`をCommandBus経由で発行し、成功したら
 * Schedule Entry側を`Granted`へ遷移させる(この横断的なオーケストレーションは
 * Handlerの責務であり、Aggregate自身はGrantPaidLeaveを直接知らない)。
 *
 * `paid_leave_grant_expiry_policy`(Phase B)がまだ無いため、有効期限は既存の暫定値
 * (付与日+2年、現行`GrantScheduledPaidLeaveHandler`と同じ)を踏襲する。
 *
 * @implements CommandHandler<ApplyScheduledGrants>
 */
class ApplyScheduledGrantsHandler implements CommandHandler
{
    public function __construct(private readonly CommandBus $commandBus) {}

    /**
     * @return array<string, string> entryId => grantId
     */
    public function handle(Command $command): array
    {
        assert($command instanceof ApplyScheduledGrants);

        $grantedIds = [];

        // `PaidLeaveScheduleAggregate`のAggregateId(=userIdから決定的に導出したUUID)は
        // `PaidLeaveAccountAggregate`(AggregateId=生のuserId)とはuuid空間が分離されているため
        // (spec.md論点14)、両者のstored_eventsストリームは互いに干渉しない。Schedule Aggregate
        // は1回retrieveし、エントリごとに`GrantPaidLeave`発行 → `applyGrant` →
        // `persist()`をループ内で行う(1エントリごとの付与が独立した業務単位であり、途中の
        // エントリで例外が起きても直前まで処理したエントリの付与・遷移は確定させたいため、
        // まとめて末尾で1回persistするのではなくエントリ単位でpersistする形を維持する)。
        $aggregate = PaidLeaveScheduleAggregate::retrieve(PaidLeaveScheduleAggregate::aggregateUuidForUser($command->userId));

        foreach ($command->entryIds as $entryId) {
            $entry = $aggregate->entry($entryId);

            if ($entry === null) {
                throw new DomainRuleException("Scheduleエントリ [{$entryId}] は存在しません。");
            }

            if ($entry['status'] !== ScheduleEntryStatus::ELIGIBLE) {
                throw new DomainRuleException("Scheduleエントリ [{$entryId}] はEligibleでないため付与できません(現在: {$entry['status']})。");
            }

            $grantedOn = $entry['scheduledOn'];
            $expiresOn = Carbon::parse($grantedOn)->addYears(2)->toDateString();

            $grantId = $this->commandBus->dispatch(new GrantPaidLeave(
                userId: $command->userId,
                grantedOn: $grantedOn,
                expiresOn: $expiresOn,
                grantedDays: $entry['candidateGrantDays'],
                grantReason: "Schedule確定付与（{$entry['category']}）",
                source: 'scheduled_batch',
            ));

            $aggregate->applyGrant(entryId: $entryId, grantId: $grantId, operatorUserId: $command->operatorUserId);
            $aggregate->persist();

            $grantedIds[$entryId] = $grantId;
        }

        return $grantedIds;
    }
}
