<?php

namespace App\Domain\PaidLeaveSchedule\Handlers;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\PaidLeaveAccount\Commands\GrantPaidLeave;
use App\Domain\PaidLeaveSchedule\Aggregates\PaidLeaveScheduleAggregate;
use App\Domain\PaidLeaveSchedule\Commands\ApplyScheduledGrants;
use App\Domain\UserManagement\Support\UserManagementStreamId;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * 管理者の一括付与操作。対象各Scheduleエントリについて`GrantPaidLeave`を発行し、
 * Schedule側をGrantedへ遷移させる(依頼書§39)。
 * Eligible以外のエントリが混じっていた場合は当該エントリのみ失敗として扱う
 * (一括操作の他の対象を巻き込まない)。
 * 渡された行は(付与予定日, 名目の継続勤務月数)の順に並べ替えてから付与する(同じ日の行は名目の月数の小さい順。
 * 月数がnullの行は同じ日の中で先)。付与IDは行IDから決めたUUIDv5にし、同じ行の再実行で二重に付与しない
 * (有給口座が同じ付与IDを拒否する)。
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

        foreach ($this->sortedEntryIds($command) as $scheduleEntryId) {
            $grantId = null;

            try {
                $aggregate = PaidLeaveScheduleAggregate::retrieve(PaidLeaveScheduleAggregate::streamIdFor($command->userId))->forUser($command->userId);
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
                    grantId: self::grantIdFor($scheduleEntryId),
                ));

                // 有給口座(GrantPaidLeave)と付与予定は別のストリームなので、上で取った`$aggregate`のまま確定できる。
                $aggregate
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

    /**
     * 付与予定の行IDから決めた、有給口座の付与ID(UUIDv5。名前空間は`UserManagementStreamId`と同じ方式)。
     */
    public static function grantIdFor(string $scheduleEntryId): string
    {
        return UserManagementStreamId::for('paid_leave_schedule_grant', $scheduleEntryId);
    }

    /**
     * 渡された行IDを(付与予定日, 名目の月数)の順に並べ替える。集約に無い行は末尾へ(付与時に「存在しません」で失敗する)。
     *
     * @return array<int, string>
     */
    private function sortedEntryIds(ApplyScheduledGrants $command): array
    {
        $entries = PaidLeaveScheduleAggregate::retrieve(PaidLeaveScheduleAggregate::streamIdFor($command->userId))->forUser($command->userId)->entries();

        $ids = array_values($command->scheduleEntryIds);
        $position = array_flip($ids);

        usort($ids, function (string $a, string $b) use ($entries, $position): int {
            $entryA = $entries[$a] ?? null;
            $entryB = $entries[$b] ?? null;

            if ($entryA === null || $entryB === null) {
                return ($entryA === null) <=> ($entryB === null) ?: $position[$a] <=> $position[$b];
            }

            return [$entryA['scheduledOn'], $entryA['nominalServiceMonths'] ?? -1, $position[$a]]
                <=> [$entryB['scheduledOn'], $entryB['nominalServiceMonths'] ?? -1, $position[$b]];
        });

        return $ids;
    }
}
