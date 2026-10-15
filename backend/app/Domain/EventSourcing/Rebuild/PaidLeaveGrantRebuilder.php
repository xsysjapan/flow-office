<?php

namespace App\Domain\EventSourcing\Rebuild;

/**
 * 有給の付与の変換(論点18 assets/event-rebuild-mapping.md 2.2・2.3)。
 *
 * - 付与(旧 paid_leave.granted・grant_revoked・warning_raised)を口座集約の grant_created・grant_revoked・
 *   grant_warning_raised に写す(grantId=旧の付与ID、口座の集約ID=利用者ID)。
 * - cutover(paid_leave_account.migrated)は、付与IDと残数で旧付与と突き合わせる。旧で再現できる付与(残数一致)は
 *   行を作らず、一致しない付与(システム外から持ち込んだ残高)は carried_over の grant_created にする。
 *   付与IDは一致するが残数が合わないものは扱えないので例外にする。
 */
final class PaidLeaveGrantRebuilder
{
    public const CARRIED_OVER = 'carried_over';

    public const DEFAULT_SOURCE = 'manual';

    /**
     * @param  list<StoredEventRow>  $rows  付与集約(aggregate=$grantId)の行(id順)。paid_leave.granted を1件含むこと
     * @return list<RebuiltEvent>
     */
    public function convertGrant(string $grantId, string $userId, array $rows): array
    {
        $events = [];
        foreach ($rows as $row) {
            if ($row->aggregateUuid !== $grantId) {
                throw new UnsupportedEventSequence("paid_leave grant {$grantId}", "想定外の付与の行: id={$row->id}");
            }
            $events[] = match ($row->eventClass) {
                'paid_leave.granted' => $this->grantCreated($row, $grantId, $userId),
                'paid_leave.grant_revoked' => RebuiltEvent::derived($row, 0, 'paid_leave_account.grant_revoked', $userId, [
                    'grantId' => $grantId,
                    'revokedByUserId' => (string) $row->get('revokedByUserId'),
                    'reason' => $row->get('reason'),
                ]),
                'paid_leave.warning_raised' => RebuiltEvent::derived($row, 0, 'paid_leave_account.grant_warning_raised', $userId, [
                    'grantId' => $grantId,
                    'warningType' => (string) $row->get('warningType'),
                    'message' => (string) $row->get('message'),
                ]),
                default => throw new UnsupportedEventSequence("paid_leave grant {$grantId}", "想定外の付与の種類: {$row->eventClass}"),
            };
        }

        return $events;
    }

    /**
     * cutover の付与を旧付与と突き合わせる。
     *
     * @param  StoredEventRow  $migrated  paid_leave_account.migrated の行(aggregate=利用者ID)
     * @param  array<string, array{granted: ?StoredEventRow, userId: string, used: float, reversed: float}>  $grantStates  旧付与ID → 旧付与の状態
     * @return array{events: list<RebuiltEvent>, outcomes: list<array{user_id: string, grant_id: string, outcome: string}>}
     */
    public function reconcileCutover(StoredEventRow $migrated, array $grantStates): array
    {
        $userId = (string) $migrated->aggregateUuid;
        $key = "paid_leave cutover {$userId}";
        $cutoverDate = (string) $migrated->get('cutoverDate');
        $grants = $migrated->get('grants', []);
        if (! is_array($grants)) {
            throw new UnsupportedEventSequence($key, 'grants が配列ではない');
        }

        $events = [];
        $outcomes = [];
        foreach ($grants as $i => $grant) {
            $grantId = (string) ($grant['grantId'] ?? '');
            $remaining = (float) $grant['remainingDaysAtCutover'];
            $state = $grantStates[$grantId] ?? null;

            if ($state !== null && $state['granted'] !== null) {
                if ($state['userId'] !== $userId) {
                    throw new UnsupportedEventSequence($key, "付与 {$grantId} の利用者が一致しない");
                }
                $expected = (float) $state['granted']->get('grantedDays') - $state['used'] + $state['reversed'];
                if (abs($expected - $remaining) > 0.000001) {
                    throw new UnsupportedEventSequence(
                        $key,
                        "付与 {$grantId} の残数が一致しない(旧で再現 {$expected}、cutover {$remaining})",
                    );
                }
                $outcomes[] = ['user_id' => $userId, 'grant_id' => $grantId, 'outcome' => 'reproduced'];

                continue;
            }

            // システム外から持ち込んだ残高。cutoverの付与IDをそのまま付与IDにする。
            $grantedOn = $grant['originalGrantedOn'] ?? null;
            $cutoverMeta = is_array($grant['cutoverMetadata'] ?? null) ? $grant['cutoverMetadata'] : [];
            $events[] = new RebuiltEvent(
                eventClass: 'paid_leave_account.grant_created',
                aggregateUuid: $userId,
                payload: [
                    'grantId' => $grantId,
                    'grantedOn' => $grantedOn !== null ? (string) $grantedOn : $cutoverDate,
                    'expiresOn' => (string) $grant['expiresOn'],
                    'grantedDays' => $remaining,
                    'grantReason' => null,
                    'source' => self::CARRIED_OVER,
                ],
                // cutoverの情報(モード・備考・元の付与日・元の付与日数)は meta に残す(付与の移行監査列は持たない)。
                meta: $migrated->meta + ['cutover' => [
                    'cutoverDate' => $cutoverDate,
                    'mode' => $cutoverMeta['mode'] ?? null,
                    'notes' => $cutoverMeta['notes'] ?? null,
                    'originalGrantedOn' => $grantedOn,
                    'originalGrantedDays' => $grant['originalGrantedDays'] ?? null,
                ]],
                createdAt: $migrated->occurredAt(),
                anchorId: $migrated->id,
                subOrder: $i,
                rebuiltFrom: $migrated->id,
                sourceVersion: null,
            );
            $outcomes[] = ['user_id' => $userId, 'grant_id' => $grantId, 'outcome' => self::CARRIED_OVER];
        }

        return ['events' => $events, 'outcomes' => $outcomes];
    }

    /**
     * 定期付与(GrantScheduledPaidLeaveHandler、d5a726b^)は grantReason を「自動付与（{ルール名}、勤続{n}か月）」で記録していた。
     * その形の付与は scheduled_batch、それ以外(手動付与)は manual とする。
     */
    public const SCHEDULED_REASON_PREFIX = '自動付与（';

    public static function sourceOf(mixed $grantReason): string
    {
        return is_string($grantReason) && str_starts_with($grantReason, self::SCHEDULED_REASON_PREFIX)
            ? 'scheduled_batch'
            : self::DEFAULT_SOURCE;
    }

    private function grantCreated(StoredEventRow $row, string $grantId, string $userId): RebuiltEvent
    {
        return RebuiltEvent::derived($row, 0, 'paid_leave_account.grant_created', $userId, [
            'grantId' => $grantId,
            'grantedOn' => (string) $row->get('grantedOn'),
            'expiresOn' => (string) $row->get('expiresOn'),
            'grantedDays' => (float) $row->get('grantedDays'),
            'grantReason' => $row->get('grantReason'),
            'source' => self::sourceOf($row->get('grantReason')),
        ]);
    }
}
