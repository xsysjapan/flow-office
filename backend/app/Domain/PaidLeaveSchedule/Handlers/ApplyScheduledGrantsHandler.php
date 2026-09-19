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
use Throwable;

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
            $grantId = null;

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
                if ($grantId !== null) {
                    // GrantPaidLeave(実際の付与)は既に成功しており、Schedule側の
                    // grantEntry()だけが失敗した(例: 状態不整合)。付与自体を取り消す
                    // 手段は無い(依頼書の設計上、付与取消は別の専用操作)ため、
                    // サイレントに握りつぶさず必ずログへ残し、Schedule側の紐付けが
                    // 手動対応待ちであることが分かるメッセージにする。
                    report($e);
                    $failed[] = [
                        'scheduleEntryId' => $scheduleEntryId,
                        'reason' => "付与(grant_id={$grantId})は成功しましたが、Scheduleエントリへの反映に失敗しました。手動確認が必要です: {$e->getMessage()}",
                    ];

                    continue;
                }

                $failed[] = ['scheduleEntryId' => $scheduleEntryId, 'reason' => $e->getMessage()];
            } catch (Throwable $e) {
                report($e);

                if ($grantId !== null) {
                    $failed[] = [
                        'scheduleEntryId' => $scheduleEntryId,
                        'reason' => "付与(grant_id={$grantId})は成功しましたが、Scheduleエントリへの反映に失敗しました。手動確認が必要です: {$e->getMessage()}",
                    ];
                } else {
                    $failed[] = ['scheduleEntryId' => $scheduleEntryId, 'reason' => '予期しないエラーが発生しました。管理者へ連絡してください。'];
                }
            }
        }

        return ['granted' => $granted, 'failed' => $failed];
    }
}
