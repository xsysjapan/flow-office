<?php

namespace App\Domain\EventSourcing\Rebuild;

use App\Domain\UserManagement\Support\UserManagementStreamId;

/**
 * 特別休暇・代休の1申請ぶんの旧イベント列を、申請の状態機械で新しいイベント列に変換する
 * (論点18 assets/event-rebuild-mapping.md 2.1・2.5・5章)。有給(PaidLeaveRequestRebuilder)と同じ状態機械。
 *
 * 旧の記録先:
 * - 申請集約(集約ID=申請ID): {kind}.requested・request_shared・usage_designated・request_approved・request_returned・
 *   request_resubmitted・request_cancelled。
 * - 付与集約(集約ID=付与ID): {kind}.used・usage_reversed(payloadの specialLeaveRequestId / compensatoryLeaveRequestId=申請ID)。
 *
 * 変換:
 * - 申請系のイベントは名前を変えず、既定値null項目(userId・targetDate・requiresGrant等)を同じ申請の内容から補完して写す。
 * - 口座集約(集約ID=UserManagementStreamId::for('{kind}_account', 利用者ID))に、申請の事実に対応する usage_designated・
 *   usage_confirmed・usage_cancelled を補う。承認は *.used の有無にかかわらず必ず usage_confirmed(allocations=*.used を付与ごとに集めたもの)。
 *   取消・差戻しは *.usage_reversed の有無にかかわらず、有効な消化があれば usage_cancelled。再提出は新しい usageId で usage_designated。
 * - 消化記録(usageId)は LeaveUsageId::for('{kind}', 申請ID, 再提出の回数)。
 *
 * 扱えない並びは UnsupportedEventSequence を投げる(推測で埋めない)。
 */
final class LeaveRequestRebuilder
{
    public const SPECIAL = 'special_leave';

    public const COMPENSATORY = 'compensatory_leave';

    private const HOURLY = 'hourly';

    /** 取消・差戻しの理由の固定文言(旧に値が無いときに使う。現行のReactorと同じ文言)。 */
    private const CANCEL_REASON = [
        self::SPECIAL => '特別休暇申請の取消',
        self::COMPENSATORY => '代休申請の取消',
    ];

    private const RETURN_REASON = [
        self::SPECIAL => '特別休暇申請の差戻しによる消化記録の取消',
        self::COMPENSATORY => '代休申請の差戻しによる消化記録の取消',
    ];

    /** 申請の内容(requested から取り、再提出で更新する)のキー。 */
    private const CONTENT_KEYS = [
        self::SPECIAL => ['userId', 'specialLeaveTypeId', 'targetDate', 'leaveType', 'hours', 'requestedDays', 'approverUserId', 'reason', 'requestGroupId'],
        self::COMPENSATORY => ['userId', 'targetDate', 'leaveType', 'hours', 'requestedDays', 'requestedMinutes', 'approverUserId', 'reason', 'requestGroupId'],
    ];

    /** 承認・差戻し・取消のイベントで、既定値nullの項目として補完するキー。 */
    private const FILL_KEYS = [
        self::SPECIAL => ['userId', 'targetDate', 'specialLeaveTypeId', 'leaveType', 'hours', 'requestedDays'],
        self::COMPENSATORY => ['userId'],
    ];

    /** 再提出のイベントで補完するキー。 */
    private const FILL_KEYS_RESUBMITTED = [
        self::SPECIAL => ['userId', 'targetDate', 'specialLeaveTypeId', 'leaveType', 'hours', 'requestedDays'],
        self::COMPENSATORY => ['userId', 'targetDate', 'leaveType', 'hours', 'requestedDays', 'requestedMinutes'],
    ];

    /** @return list<string> 申請集約(aggregate=申請ID)の旧イベント */
    public static function requestClasses(string $kind): array
    {
        return array_map(fn (string $suffix) => "{$kind}.{$suffix}", [
            'requested', 'request_shared', 'usage_designated', 'request_approved', 'request_returned', 'request_resubmitted', 'request_cancelled',
        ]);
    }

    /** @return list<string> 付与集約の旧の消化イベント */
    public static function usageClasses(string $kind): array
    {
        return ["{$kind}.used", "{$kind}.usage_reversed"];
    }

    /** 消化の行が申請IDを持つpayloadのキー。 */
    public static function usageRequestKey(string $kind): string
    {
        return $kind === self::SPECIAL ? 'specialLeaveRequestId' : 'compensatoryLeaveRequestId';
    }

    public static function accountStreamId(string $kind, string $userId): string
    {
        return UserManagementStreamId::for("{$kind}_account", $userId);
    }

    /**
     * @param  list<StoredEventRow>  $requestRows  申請集約(aggregate=$requestId)の行(id順)
     * @param  list<StoredEventRow>  $usageRows  付与集約の *.used・*.usage_reversed のうち申請IDが $requestId の行(id順)
     */
    public function rebuild(string $kind, string $requestId, array $requestRows, array $usageRows): PaidLeaveRequestRebuild
    {
        $key = "{$kind} request {$requestId}";

        foreach ($requestRows as $row) {
            if ($row->aggregateUuid !== $requestId || ! in_array($row->eventClass, self::requestClasses($kind), true)) {
                throw new UnsupportedEventSequence($key, "想定外の申請の行: id={$row->id} {$row->eventClass}");
            }
        }
        foreach ($usageRows as $row) {
            if (! in_array($row->eventClass, self::usageClasses($kind), true)) {
                throw new UnsupportedEventSequence($key, "想定外の消化の行: id={$row->id} {$row->eventClass}");
            }
        }

        $requested = $this->one($requestRows, "{$kind}.requested", $key);
        $designated = $this->optional($requestRows, "{$kind}.usage_designated", $key);

        $content = [];
        foreach (self::CONTENT_KEYS[$kind] as $contentKey) {
            $content[$contentKey] = $requested->get($contentKey);
        }
        $applicant = (string) $content['userId'];
        $account = self::accountStreamId($kind, $applicant);

        $items = [];
        $map = [
            "{$kind}.requested" => 'requested',
            "{$kind}.request_shared" => 'shared',
            "{$kind}.request_approved" => 'approved',
            "{$kind}.request_returned" => 'returned',
            "{$kind}.request_resubmitted" => 'resubmitted',
            "{$kind}.request_cancelled" => 'cancelled',
        ];
        foreach ($requestRows as $row) {
            if (isset($map[$row->eventClass])) {
                $items[] = ['kind' => $map[$row->eventClass], 'row' => $row];
            }
        }
        usort($items, fn (array $a, array $b) => $a['row']->id <=> $b['row']->id);

        $approvalIds = [];
        $releaseIds = [];
        foreach ($items as $item) {
            if ($item['kind'] === 'approved') {
                $approvalIds[] = $item['row']->id;
            }
            if (in_array($item['kind'], ['returned', 'cancelled'], true)) {
                $releaseIds[] = $item['row']->id;
            }
        }

        // *.used は直前にある承認の充当、*.usage_reversed は直前にある差戻し・取消の解放として扱う。
        $usedByApproval = [];
        $reversedByRelease = [];
        foreach ($usageRows as $row) {
            if ($row->eventClass === "{$kind}.used") {
                $owner = $this->latestBefore($approvalIds, $row->id);
                if ($owner === null) {
                    throw new UnsupportedEventSequence($key, "承認より前の消化の行: id={$row->id} {$row->eventClass}");
                }
                $usedByApproval[$owner][] = $row;
            } else {
                $owner = $this->latestBefore($releaseIds, $row->id);
                if ($owner === null) {
                    throw new UnsupportedEventSequence($key, "差戻し・取消より前の消化の取消の行: id={$row->id} {$row->eventClass}");
                }
                $reversedByRelease[$owner][] = $row;
            }
        }

        $events = [];
        $consumed = [];
        $state = 'none';
        $resubmits = 0;
        $active = false;
        $lastRelease = null;
        /** @var list<array<string, mixed>> $confirmedAllocations 確定済みの充当(解放の既定値) */
        $confirmedAllocations = [];

        $usageId = fn (int $k): string => LeaveUsageId::for($kind, $requestId, $k);

        foreach ($items as $item) {
            $itemKind = $item['kind'];
            /** @var StoredEventRow $row */
            $row = $item['row'];

            if ($itemKind === 'requested') {
                if ($state !== 'none') {
                    throw new UnsupportedEventSequence($key, '申請が2回ある');
                }
                $events[] = $this->carry($row, $requestId, $row->payload);
                $consumed[$row->id] = true;
                $events[] = $this->designation(
                    $kind,
                    $designated ?? $row,
                    $designated !== null ? 0 : 1,
                    $usageId(0),
                    $requestId,
                    $applicant,
                    $account,
                    $content,
                    $designated,
                    $designated !== null ? null : $row->id,
                );
                if ($designated !== null) {
                    $consumed[$designated->id] = true;
                }
                $state = 'requested';
                $active = true;

                continue;
            }

            if ($itemKind === 'shared') {
                if ($state !== 'requested') {
                    throw new UnsupportedEventSequence($key, "申請の共有が状態 {$state} で起きた");
                }
                $events[] = $this->carry($row, $requestId, $row->payload);
                $consumed[$row->id] = true;
                $state = 'shared';

                continue;
            }

            if ($itemKind === 'approved') {
                if (! in_array($state, ['requested', 'shared'], true)) {
                    throw new UnsupportedEventSequence($key, "承認が状態 {$state} で起きた");
                }
                $usedRows = $usedByApproval[$row->id] ?? [];
                $allocations = $this->allocations($kind, $content, $usedRows);
                $unallocated = $this->unallocated($kind, $content, $allocations);

                $payload = $this->fill($row->payload, self::FILL_KEYS[$kind], $content);
                if ($kind === self::SPECIAL) {
                    // 承認時点の種別の設定は履歴が無いため、*.used があれば残数を要する、無ければ要しないと判定する。
                    $payload['requiresGrant'] = $row->get('requiresGrant') ?? ($usedRows !== []);
                }
                $events[] = $this->carry($row, $requestId, $payload);
                $consumed[$row->id] = true;

                $confirmedPayload = $kind === self::SPECIAL
                    ? ['usageId' => $usageId($resubmits), 'allocations' => $allocations, 'unallocatedDays' => $unallocated['days']]
                    : [
                        'userId' => $applicant,
                        'usageId' => $usageId($resubmits),
                        'allocations' => $allocations,
                        'unallocatedDays' => $unallocated['days'],
                        'unallocatedMinutes' => $unallocated['minutes'],
                    ];
                $events[] = RebuiltEvent::derived($row, 1, "{$kind}_account.usage_confirmed", $account, $confirmedPayload, $row->id);
                foreach ($usedRows as $used) {
                    $consumed[$used->id] = true;
                }
                $confirmedAllocations = $allocations;
                $state = 'approved';
                $active = true;

                continue;
            }

            if ($itemKind === 'returned' || $itemKind === 'cancelled') {
                $allowed = $itemKind === 'returned' ? ['requested', 'shared', 'approved'] : ['requested', 'shared', 'returned', 'approved'];
                if (! in_array($state, $allowed, true)) {
                    throw new UnsupportedEventSequence($key, ($itemKind === 'returned' ? '差戻し' : '取消')."が状態 {$state} で起きた");
                }
                $events[] = $this->carry($row, $requestId, $this->fill($row->payload, self::FILL_KEYS[$kind], $content));
                $consumed[$row->id] = true;

                if ($active) {
                    $reason = $itemKind === 'returned'
                        ? self::RETURN_REASON[$kind]
                        : (string) ($row->get('reason') ?? self::CANCEL_REASON[$kind]);
                    $released = $this->releasedAllocations($kind, $reversedByRelease[$row->id] ?? [], $confirmedAllocations);
                    $cancelledPayload = $kind === self::SPECIAL
                        ? ['usageId' => $usageId($resubmits), 'releasedAllocations' => $released, 'reason' => $reason]
                        : ['userId' => $applicant, 'usageId' => $usageId($resubmits), 'releasedAllocations' => $released, 'reason' => $reason];
                    $events[] = RebuiltEvent::derived($row, 1, "{$kind}_account.usage_cancelled", $account, $cancelledPayload, $row->id);
                    $operator = $itemKind === 'returned' ? $row->get('returnedByUserId') : $row->get('cancelledByUserId');
                    $lastRelease = ['row' => $row, 'operatorUserId' => (string) ($operator ?? $applicant)];
                }
                foreach ($reversedByRelease[$row->id] ?? [] as $reversed) {
                    $consumed[$reversed->id] = true;
                }
                $state = $itemKind === 'returned' ? 'returned' : 'cancelled';
                $active = false;
                $confirmedAllocations = [];

                continue;
            }

            if ($itemKind === 'resubmitted') {
                if ($state !== 'returned') {
                    throw new UnsupportedEventSequence($key, "再提出が状態 {$state} で起きた");
                }
                $resubmits++;
                $payload = $this->fill($row->payload, self::FILL_KEYS_RESUBMITTED[$kind], $content);
                $events[] = $this->carry($row, $requestId, $payload);
                $consumed[$row->id] = true;
                foreach (self::CONTENT_KEYS[$kind] as $contentKey) {
                    if (($payload[$contentKey] ?? null) !== null) {
                        $content[$contentKey] = $payload[$contentKey];
                    }
                }
                $events[] = $this->designation($kind, $row, 1, $usageId($resubmits), $requestId, $applicant, $account, $content, null, $row->id);
                $state = 'requested';
                $active = true;

                continue;
            }

            throw new UnsupportedEventSequence($key, "未対応の項目: {$itemKind}");
        }

        if ($state === 'none') {
            throw new UnsupportedEventSequence($key, '申請の作成が無い');
        }
        foreach ($usageRows as $row) {
            if (! isset($consumed[$row->id])) {
                throw new UnsupportedEventSequence($key, "変換されなかった消化の行: id={$row->id} {$row->eventClass}");
            }
        }
        foreach ($requestRows as $row) {
            if (! isset($consumed[$row->id])) {
                throw new UnsupportedEventSequence($key, "変換されなかった申請の行: id={$row->id} {$row->eventClass}");
            }
        }

        return new PaidLeaveRequestRebuild(
            events: $events,
            consumedIds: array_map('intval', array_keys($consumed)),
            finalState: $state,
            resubmitCount: $resubmits,
            userId: $applicant,
            targetDate: (string) $content['targetDate'],
            lastRelease: $lastRelease,
        );
    }

    /**
     * 申請集約のイベントを、名前を変えず(補完した)payloadで写す。位置・meta・発生日時は元の行のまま。
     *
     * @param  array<string, mixed>  $payload
     */
    private function carry(StoredEventRow $row, string $requestId, array $payload): RebuiltEvent
    {
        return RebuiltEvent::derived($row, 0, $row->eventClass, $requestId, $payload);
    }

    /**
     * 既定値nullの項目を、同じ申請の内容から補完する(値があるものは変えない)。
     *
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function fill(array $payload, array $keys, array $content): array
    {
        foreach ($keys as $fillKey) {
            if (($payload[$fillKey] ?? null) === null && ($content[$fillKey] ?? null) !== null) {
                $payload[$fillKey] = $content[$fillKey];
            }
        }

        return $payload;
    }

    /**
     * 消化の指定(口座集約の usage_designated)。旧に指定の行があれば、その値(消化日・日数・分数・種類)を使う。
     *
     * @param  array<string, mixed>  $content
     */
    private function designation(
        string $kind,
        StoredEventRow $anchor,
        int $sub,
        string $usageId,
        string $requestId,
        string $applicant,
        string $account,
        array $content,
        ?StoredEventRow $old,
        ?int $rebuiltFrom,
    ): RebuiltEvent {
        if ($old !== null) {
            $usedOn = (string) $old->get('usedOn');
            $usageType = (string) $old->get('usageType');
            $usedDays = (float) $old->get('usedDays');
            $usedMinutes = $old->get('usedMinutes') !== null ? (int) $old->get('usedMinutes') : null;
        } else {
            $usedOn = (string) $content['targetDate'];
            $usageType = (string) $content['leaveType'];
            $usedDays = (float) $content['requestedDays'];
            if ($kind === self::SPECIAL) {
                $usedMinutes = $content['hours'] !== null ? (int) round(((float) $content['hours']) * 60) : null;
            } else {
                $usedMinutes = $content['requestedMinutes'] !== null ? (int) $content['requestedMinutes'] : null;
            }
        }

        $payload = $kind === self::SPECIAL
            ? [
                'usageId' => $usageId,
                'requestId' => $requestId,
                'specialLeaveTypeId' => (int) $content['specialLeaveTypeId'],
                'usedOn' => $usedOn,
                'usageType' => $usageType,
                'usedDays' => $usedDays,
                'usedMinutes' => $usedMinutes,
                'userId' => $applicant,
            ]
            : [
                'userId' => $applicant,
                'usageId' => $usageId,
                'requestId' => $requestId,
                'usedOn' => $usedOn,
                'usageType' => $usageType,
                'usedDays' => $usedDays,
                'usedMinutes' => $usedMinutes,
            ];

        return RebuiltEvent::derived($anchor, $sub, "{$kind}_account.usage_designated", $account, $payload, $old === null ? $rebuiltFrom : null);
    }

    /**
     * 承認の充当(*.used を付与ごとに集める。付与の出現順)。
     *
     * @param  array<string, mixed>  $content
     * @param  list<StoredEventRow>  $usedRows
     * @return list<array<string, mixed>>
     */
    private function allocations(string $kind, array $content, array $usedRows): array
    {
        $hourly = $kind === self::COMPENSATORY && ($content['leaveType'] ?? null) === self::HOURLY;
        $byGrant = [];
        foreach ($usedRows as $used) {
            $grantId = (string) $used->aggregateUuid;
            $byGrant[$grantId]['days'] = ($byGrant[$grantId]['days'] ?? 0.0) + (float) $used->get('usedDays');
            $byGrant[$grantId]['minutes'] = ($byGrant[$grantId]['minutes'] ?? 0) + (int) ($used->get('usedMinutes') ?? 0);
        }

        $allocations = [];
        foreach ($byGrant as $grantId => $amount) {
            if ($kind === self::SPECIAL) {
                $allocations[] = ['grantId' => (string) $grantId, 'allocatedDays' => (float) $amount['days']];
            } else {
                $allocations[] = [
                    'grantId' => (string) $grantId,
                    'allocatedDays' => $hourly ? 0.0 : (float) $amount['days'],
                    'allocatedMinutes' => $hourly ? (int) $amount['minutes'] : 0,
                ];
            }
        }

        return $allocations;
    }

    /**
     * 未充当量。特別休暇は申請日数−充当合計(残数を要しない=*.usedが無い場合は0)、代休は日数か分数(時間単位)の不足分。
     *
     * @param  array<string, mixed>  $content
     * @param  list<array<string, mixed>>  $allocations
     * @return array{days: float, minutes: int}
     */
    private function unallocated(string $kind, array $content, array $allocations): array
    {
        if ($kind === self::SPECIAL) {
            if ($allocations === []) {
                return ['days' => 0.0, 'minutes' => 0];
            }
            $allocated = (float) array_sum(array_column($allocations, 'allocatedDays'));

            return ['days' => max(0.0, (float) $content['requestedDays'] - $allocated), 'minutes' => 0];
        }

        if (($content['leaveType'] ?? null) === self::HOURLY) {
            $allocated = (int) array_sum(array_column($allocations, 'allocatedMinutes'));

            return ['days' => 0.0, 'minutes' => max(0, (int) ($content['requestedMinutes'] ?? 0) - $allocated)];
        }

        $allocated = (float) array_sum(array_column($allocations, 'allocatedDays'));

        return ['days' => max(0.0, (float) $content['requestedDays'] - $allocated), 'minutes' => 0];
    }

    /**
     * 解放する充当。*.usage_reversed があれば付与ごとにその値、無ければ確定済みの充当の全部。
     *
     * @param  list<StoredEventRow>  $reversedRows
     * @param  list<array<string, mixed>>  $confirmedAllocations
     * @return list<array<string, mixed>>
     */
    private function releasedAllocations(string $kind, array $reversedRows, array $confirmedAllocations): array
    {
        $released = [];
        if ($reversedRows !== []) {
            $byGrant = [];
            foreach ($reversedRows as $reversed) {
                $grantId = (string) $reversed->aggregateUuid;
                $byGrant[$grantId]['days'] = ($byGrant[$grantId]['days'] ?? 0.0) + (float) $reversed->get('usedDays');
                $byGrant[$grantId]['minutes'] = ($byGrant[$grantId]['minutes'] ?? 0) + (int) ($reversed->get('usedMinutes') ?? 0);
            }
            foreach ($byGrant as $grantId => $amount) {
                $released[] = $kind === self::SPECIAL
                    ? ['grantId' => (string) $grantId, 'releasedDays' => (float) $amount['days']]
                    : ['grantId' => (string) $grantId, 'releasedDays' => (float) $amount['days'], 'releasedMinutes' => (int) $amount['minutes']];
            }

            return $released;
        }

        foreach ($confirmedAllocations as $allocation) {
            $released[] = $kind === self::SPECIAL
                ? ['grantId' => $allocation['grantId'], 'releasedDays' => $allocation['allocatedDays']]
                : ['grantId' => $allocation['grantId'], 'releasedDays' => $allocation['allocatedDays'], 'releasedMinutes' => $allocation['allocatedMinutes']];
        }

        return $released;
    }

    /** @param  list<int>  $ids */
    private function latestBefore(array $ids, int $id): ?int
    {
        $owner = null;
        foreach ($ids as $candidate) {
            if ($candidate < $id) {
                $owner = $candidate;
            }
        }

        return $owner;
    }

    /** @param  list<StoredEventRow>  $rows */
    private function one(array $rows, string $class, string $key): StoredEventRow
    {
        $found = array_values(array_filter($rows, fn (StoredEventRow $r) => $r->eventClass === $class));
        if (count($found) !== 1) {
            throw new UnsupportedEventSequence($key, "{$class} が1件ではない(".count($found).'件)');
        }

        return $found[0];
    }

    /** @param  list<StoredEventRow>  $rows */
    private function optional(array $rows, string $class, string $key): ?StoredEventRow
    {
        $found = array_values(array_filter($rows, fn (StoredEventRow $r) => $r->eventClass === $class));
        if (count($found) > 1) {
            throw new UnsupportedEventSequence($key, "{$class} が複数ある");
        }

        return $found[0] ?? null;
    }
}
