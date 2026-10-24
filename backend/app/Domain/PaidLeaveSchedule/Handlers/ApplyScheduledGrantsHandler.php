<?php

namespace App\Domain\PaidLeaveSchedule\Handlers;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Contracts\Command;
use App\Domain\EventSourcing\Contracts\CommandHandler;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\PaidLeaveAccount\Commands\GrantPaidLeaves;
use App\Domain\PaidLeaveSchedule\Aggregates\PaidLeaveScheduleAggregate;
use App\Domain\PaidLeaveSchedule\Commands\ApplyScheduledGrants;
use App\Domain\UserManagement\Support\UserManagementStreamId;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * 管理者の一括付与操作。対象各Scheduleエントリについて`GrantPaidLeaves`を発行し、
 * Schedule側をGrantedへ遷移させる(依頼書§39)。
 * Eligible以外のエントリが混じっていた場合は当該エントリのみ失敗として扱う
 * (一括操作の他の対象を巻き込まない)。
 * 渡された行は(付与予定日, 名目の継続勤務月数)の順に並べ替えてから付与する(同じ日の行は名目の月数の小さい順。
 * 月数がnullの行は同じ日の中で先)。付与IDは行IDから決めたUUIDv5にし、同じ行の再実行で二重に付与しない
 * (有給口座が同じ付与IDを拒否する)。
 * まとめて付与する2件(組)は1つの単位として、有給口座の1回のpersist(`GrantPaidLeaves`)で2件を続けて付与し、
 * 片方の付与に失敗したらもう片方も付与しない(付与予定側のGrantedも1回のpersist)。組の片方だけが渡された行は付与しない。
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

        foreach ($this->units($command) as $unit) {
            $scheduleEntryIds = $unit['entryIds'];

            if ($unit['error'] !== null) {
                foreach ($scheduleEntryIds as $scheduleEntryId) {
                    $failed[] = ['scheduleEntryId' => $scheduleEntryId, 'reason' => $unit['error']];
                }

                continue;
            }

            $grantIds = null;

            try {
                $aggregate = PaidLeaveScheduleAggregate::retrieve(PaidLeaveScheduleAggregate::streamIdFor($command->userId))->forUser($command->userId);

                $grants = [];
                foreach ($scheduleEntryIds as $scheduleEntryId) {
                    $entry = $aggregate->entry($scheduleEntryId);

                    if ($entry['status'] !== PaidLeaveScheduleAggregate::STATUS_ELIGIBLE) {
                        throw new DomainRuleException("Scheduleエントリ [{$scheduleEntryId}] はEligible状態ではないため付与できません。");
                    }

                    $grants[] = [
                        'grantId' => self::grantIdFor($scheduleEntryId),
                        'grantedOn' => $entry['scheduledOn'],
                        'expiresOn' => Carbon::parse($entry['scheduledOn'])->addYears(2)->toDateString(),
                        'grantedDays' => $entry['candidateGrantDays'],
                        'grantReason' => '付与予定Scheduleからの一括付与',
                        'source' => 'scheduled_batch',
                    ];
                }

                // まとめて付与する2件は、有給口座の1回のpersistで記録する(片方だけが付与されない)。
                $grantIds = $this->commandBus->dispatch(new GrantPaidLeaves(userId: $command->userId, grants: $grants));

                // 有給口座(GrantPaidLeaves)と付与予定は別のストリームなので、上で取った`$aggregate`のまま確定できる。
                // 組の2件は1回のpersistで記録する。
                foreach ($scheduleEntryIds as $index => $scheduleEntryId) {
                    $aggregate->grantEntry($scheduleEntryId, $grantIds[$index], $command->operatorUserId);
                }
                $aggregate->persist();

                foreach ($scheduleEntryIds as $index => $scheduleEntryId) {
                    $granted[] = ['scheduleEntryId' => $scheduleEntryId, 'grantId' => $grantIds[$index]];
                }
            } catch (DomainRuleException $e) {
                if ($grantIds !== null) {
                    // GrantPaidLeaves(実際の付与)は既に成功しており、Schedule側の
                    // grantEntry()だけが失敗した(例: 状態不整合)。付与自体を取り消す
                    // 手段は無い(依頼書の設計上、付与取消は別の専用操作)ため、
                    // サイレントに握りつぶさず必ずログへ残し、Schedule側の紐付けが
                    // 手動対応待ちであることが分かるメッセージにする。
                    report($e);
                    foreach ($scheduleEntryIds as $index => $scheduleEntryId) {
                        $failed[] = [
                            'scheduleEntryId' => $scheduleEntryId,
                            'reason' => "付与(grant_id={$grantIds[$index]})は成功しましたが、Scheduleエントリへの反映に失敗しました。手動確認が必要です: {$e->getMessage()}",
                        ];
                    }

                    continue;
                }

                foreach ($scheduleEntryIds as $scheduleEntryId) {
                    $failed[] = ['scheduleEntryId' => $scheduleEntryId, 'reason' => $e->getMessage()];
                }
            } catch (Throwable $e) {
                report($e);

                foreach ($scheduleEntryIds as $index => $scheduleEntryId) {
                    if ($grantIds !== null) {
                        $failed[] = [
                            'scheduleEntryId' => $scheduleEntryId,
                            'reason' => "付与(grant_id={$grantIds[$index]})は成功しましたが、Scheduleエントリへの反映に失敗しました。手動確認が必要です: {$e->getMessage()}",
                        ];
                    } else {
                        $failed[] = ['scheduleEntryId' => $scheduleEntryId, 'reason' => '予期しないエラーが発生しました。管理者へ連絡してください。'];
                    }
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
     * 付与の単位に分ける。まとめて付与する2件(`PaidLeaveScheduleAggregate::pairedEntryId`)は名目の月数の小さい順の1単位にし、
     * 片方だけが渡されたときは、その行を付与せずに失敗の単位にする(組は同時にしか付与できない)。
     *
     * @return array<int, array{entryIds: array<int, string>, error: ?string}>
     */
    private function units(ApplyScheduledGrants $command): array
    {
        $aggregate = PaidLeaveScheduleAggregate::retrieve(PaidLeaveScheduleAggregate::streamIdFor($command->userId))->forUser($command->userId);
        $sortedIds = $this->sortedEntryIds($command);
        $requested = array_flip($sortedIds);

        $units = [];
        $handled = [];

        foreach ($sortedIds as $entryId) {
            if (isset($handled[$entryId])) {
                continue;
            }

            $partnerId = $aggregate->pairedEntryId($entryId);

            if ($partnerId === null) {
                $units[] = ['entryIds' => [$entryId], 'error' => null];

                continue;
            }

            if (! isset($requested[$partnerId])) {
                $units[] = ['entryIds' => [$entryId], 'error' => 'まとめて付与する2件は、同時に付与する必要があります(組の相手が指定されていません)。'];

                continue;
            }

            // sortedEntryIdsは(付与予定日, 名目の月数)順なので、先に出た方が名目の小さい行。
            $units[] = ['entryIds' => [$entryId, $partnerId], 'error' => null];
            $handled[$partnerId] = true;
        }

        return $units;
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
