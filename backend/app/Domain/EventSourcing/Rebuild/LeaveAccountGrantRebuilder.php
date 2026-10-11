<?php

namespace App\Domain\EventSourcing\Rebuild;

/**
 * 特別休暇・代休の付与の変換(論点18 assets/event-rebuild-mapping.md 2.2)。付与集約(集約ID=付与ID)の旧イベントを、
 * 利用者の口座集約(集約ID=UserManagementStreamId::for('{kind}_account', 利用者ID))の付与イベントに写す。
 *
 * - 特別休暇: special_leave.granted → special_leave_account.grant_registered、grant_revoked → grant_revoked。
 * - 代休: compensatory_leave.grant_synced / manually_granted / grant_removed / grant_confirmed / grant_cancelled →
 *   compensatory_leave_account.grant_synced / grant_manually_granted / grant_removed / grant_confirmed / grant_cancelled。
 *   利用者IDは同じ付与の作成イベント(grant_synced・manually_granted)から取る。
 */
final class LeaveAccountGrantRebuilder
{
    public const SPECIAL_CLASSES = ['special_leave.granted', 'special_leave.grant_revoked'];

    public const COMPENSATORY_CLASSES = [
        'compensatory_leave.grant_synced',
        'compensatory_leave.manually_granted',
        'compensatory_leave.grant_removed',
        'compensatory_leave.grant_confirmed',
        'compensatory_leave.grant_cancelled',
    ];

    /**
     * @param  list<StoredEventRow>  $rows  付与集約(aggregate=$grantId)の行(id順)。special_leave.granted を1件含むこと
     * @return array{userId: string, events: list<RebuiltEvent>}
     */
    public function convertSpecial(string $grantId, array $rows): array
    {
        $key = "special_leave grant {$grantId}";
        $userId = $this->creationUserId($rows, ['special_leave.granted'], $key);
        $account = LeaveRequestRebuilder::accountStreamId(LeaveRequestRebuilder::SPECIAL, $userId);

        $events = [];
        foreach ($rows as $row) {
            $this->assertOwn($row, $grantId, self::SPECIAL_CLASSES, $key);
            $events[] = match ($row->eventClass) {
                'special_leave.granted' => RebuiltEvent::derived($row, 0, 'special_leave_account.grant_registered', $account, [
                    'grantId' => $grantId,
                    'specialLeaveTypeId' => (int) $row->get('specialLeaveTypeId'),
                    'grantedOn' => (string) $row->get('grantedOn'),
                    'expiresOn' => $row->get('expiresOn'),
                    'grantedDays' => (float) $row->get('grantedDays'),
                    'grantReason' => $row->get('grantReason'),
                    'userId' => $userId,
                ]),
                'special_leave.grant_revoked' => RebuiltEvent::derived($row, 0, 'special_leave_account.grant_revoked', $account, [
                    'grantId' => $grantId,
                    'revokedByUserId' => (string) $row->get('revokedByUserId'),
                    'reason' => $row->get('reason'),
                ]),
            };
        }

        return ['userId' => $userId, 'events' => $events];
    }

    /**
     * @param  list<StoredEventRow>  $rows  付与集約(aggregate=$grantId)の行(id順)。grant_synced か manually_granted を含むこと
     * @return array{userId: string, events: list<RebuiltEvent>}
     */
    public function convertCompensatory(string $grantId, array $rows): array
    {
        $key = "compensatory_leave grant {$grantId}";
        $userId = $this->creationUserId($rows, ['compensatory_leave.grant_synced', 'compensatory_leave.manually_granted'], $key);
        $account = LeaveRequestRebuilder::accountStreamId(LeaveRequestRebuilder::COMPENSATORY, $userId);

        $events = [];
        foreach ($rows as $row) {
            $this->assertOwn($row, $grantId, self::COMPENSATORY_CLASSES, $key);
            $events[] = match ($row->eventClass) {
                'compensatory_leave.grant_synced' => RebuiltEvent::derived($row, 0, 'compensatory_leave_account.grant_synced', $account, [
                    'userId' => $userId,
                    'grantId' => $grantId,
                    'sourceWorkDate' => (string) $row->get('workDate'),
                    'grantedDays' => (float) $row->get('grantedDays'),
                    'grantedMinutes' => $this->intOrNull($row->get('grantedMinutes')),
                ]),
                'compensatory_leave.manually_granted' => RebuiltEvent::derived($row, 0, 'compensatory_leave_account.grant_manually_granted', $account, [
                    'userId' => $userId,
                    'grantId' => $grantId,
                    'sourceWorkDate' => (string) $row->get('workDate'),
                    'grantedDays' => (float) $row->get('grantedDays'),
                    'grantedMinutes' => $this->intOrNull($row->get('grantedMinutes')),
                    'expiresOn' => $row->get('expiresOn'),
                    'grantReason' => $row->get('grantReason'),
                ]),
                'compensatory_leave.grant_removed' => RebuiltEvent::derived($row, 0, 'compensatory_leave_account.grant_removed', $account, [
                    'userId' => $userId,
                    'grantId' => $grantId,
                    'reason' => (string) $row->get('reason'),
                ]),
                'compensatory_leave.grant_confirmed' => RebuiltEvent::derived($row, 0, 'compensatory_leave_account.grant_confirmed', $account, [
                    'userId' => $userId,
                    'grantId' => $grantId,
                    'confirmedAt' => (string) $row->get('confirmedAt'),
                    'expiresOn' => $row->get('expiresOn'),
                ]),
                'compensatory_leave.grant_cancelled' => RebuiltEvent::derived($row, 0, 'compensatory_leave_account.grant_cancelled', $account, [
                    'userId' => $userId,
                    'grantId' => $grantId,
                    'cancelledByUserId' => (string) $row->get('cancelledByUserId'),
                    'reason' => $row->get('reason'),
                ]),
            };
        }

        return ['userId' => $userId, 'events' => $events];
    }

    /**
     * @param  list<StoredEventRow>  $rows
     * @param  list<string>  $creationClasses
     */
    private function creationUserId(array $rows, array $creationClasses, string $key): string
    {
        foreach ($rows as $row) {
            if (in_array($row->eventClass, $creationClasses, true) && $row->get('userId') !== null) {
                return (string) $row->get('userId');
            }
        }

        throw new UnsupportedEventSequence($key, '付与の作成('.implode(' / ', $creationClasses).')が無い');
    }

    /** @param  list<string>  $classes */
    private function assertOwn(StoredEventRow $row, string $grantId, array $classes, string $key): void
    {
        if ($row->aggregateUuid !== $grantId || ! in_array($row->eventClass, $classes, true)) {
            throw new UnsupportedEventSequence($key, "想定外の付与の行: id={$row->id} {$row->eventClass}");
        }
    }

    private function intOrNull(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
