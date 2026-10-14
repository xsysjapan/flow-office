<?php

namespace App\Domain\EventSourcing\Rebuild;

/**
 * 有給の1申請ぶんの旧イベント列を、申請の状態機械で新しいイベント列に変換する
 * (論点18 assets/event-rebuild-mapping.md 2.1・5章)。
 *
 * 対象は旧の申請集約(aggregate=申請ID)の paid_leave.* 申請系と、付与集約の paid_leave.used・usage_reversed
 * (paidLeaveRequestId=申請ID)、申請のワークフロー(workflow_request.*)。ワークフローの行は読むだけで
 * 残す(workflow_request.* は休暇以外のイベントなので写す)。
 *
 * 状態: none → requested → shared → approved → (returned → 再提出で requested に戻る) → cancelled。
 * 消化記録(usageId)は申請IDと再提出の回数(k)から決まる LeaveUsageId::for('paid_leave', 申請ID, k)。
 * 扱えない並びは UnsupportedEventSequence を投げる(推測で埋めない)。
 */
final class PaidLeaveRequestRebuilder
{
    public const KIND = 'paid_leave';

    /** 申請集約(aggregate=申請ID)の旧イベント。 */
    public const REQUEST_CLASSES = [
        'paid_leave.requested',
        'paid_leave.request_shared',
        'paid_leave.usage_designated',
        'paid_leave.request_approved',
        'paid_leave.request_returned',
        'paid_leave.request_cancelled',
    ];

    public const USAGE_CLASSES = [
        'paid_leave.used',
        'paid_leave.usage_reversed',
    ];

    /** cutover後(申請の記録先が口座イベント)の消化の行。paid_leave_account.* のうち申請に属するもの。 */
    public const ACCOUNT_USAGE_CLASSES = [
        'paid_leave_account.usage_designated',
        'paid_leave_account.usage_confirmed',
        'paid_leave_account.usage_cancelled',
        'paid_leave_account.usage_allocated',
        'paid_leave_account.usage_allocation_released',
    ];

    public const RETURN_REASON = '差戻しによる取消';

    public const CANCEL_REASON = '有給申請の取消';

    /**
     * @param  list<StoredEventRow>  $requestRows  申請集約(aggregate=$requestId)の行(id順)
     * @param  list<StoredEventRow>  $usageRows  付与集約の paid_leave.used・usage_reversed のうち paidLeaveRequestId=$requestId の行(id順)
     * @param  list<StoredEventRow>  $workflowRows  申請のワークフロー(paid_leave.request_sharedのworkflowRequestId)の workflow_request.* 行(id順)
     */
    public function rebuild(string $requestId, array $requestRows, array $usageRows, array $workflowRows): PaidLeaveRequestRebuild
    {
        $key = "paid_leave request {$requestId}";

        foreach ($requestRows as $row) {
            if ($row->aggregateUuid !== $requestId || ! in_array($row->eventClass, self::REQUEST_CLASSES, true)) {
                throw new UnsupportedEventSequence($key, "想定外の申請の行: id={$row->id} {$row->eventClass}");
            }
        }
        foreach ($usageRows as $row) {
            if (! in_array($row->eventClass, self::USAGE_CLASSES, true)) {
                throw new UnsupportedEventSequence($key, "想定外の消化の行: id={$row->id} {$row->eventClass}");
            }
        }

        $requested = $this->one($requestRows, 'paid_leave.requested', $key);
        $designated = $this->optional($requestRows, 'paid_leave.usage_designated', $key);
        $shared = $this->optional($requestRows, 'paid_leave.request_shared', $key);
        $workflowId = $shared?->get('workflowRequestId');
        $workflowId = $workflowId !== null ? (string) $workflowId : null;

        $applicant = (string) $requested->get('userId');
        $items = $this->timeline($requestRows, $workflowRows);
        $approvalIds = [];
        foreach ($items as $item) {
            if ($item['kind'] === 'approved') {
                $approvalIds[] = $item['row']->id;
            }
        }

        // 消化の行は、直前にある承認の充当として扱う(承認より前の消化は扱えない)。
        $usageByApproval = [];
        foreach ($usageRows as $row) {
            $owner = null;
            foreach ($approvalIds as $approvalId) {
                if ($approvalId < $row->id) {
                    $owner = $approvalId;
                }
            }
            if ($owner === null) {
                throw new UnsupportedEventSequence($key, "承認より前の消化の行: id={$row->id} {$row->eventClass}");
            }
            if ($row->eventClass === 'paid_leave.used') {
                $usageByApproval[$owner][] = $row;
            }
        }

        $events = [];
        $consumed = [];
        $state = 'none';
        $resubmits = 0;
        $active = false;
        $lastRelease = null;
        /** @var list<array{grantId: string, days: float}> $allocations */
        $allocations = [];

        $usageId = fn (int $k): string => LeaveUsageId::for(self::KIND, $requestId, $k);

        foreach ($items as $item) {
            $kind = $item['kind'];
            /** @var StoredEventRow $row */
            $row = $item['row'];
            $isWorkflow = $item['source'] === 'workflow';

            if ($kind === 'requested') {
                if ($state !== 'none') {
                    throw new UnsupportedEventSequence($key, '申請が2回ある');
                }
                $events[] = RebuiltEvent::derived($row, 0, 'paid_leave_request.requested', $requestId, [
                    'userId' => $applicant,
                    'targetDate' => (string) $requested->get('targetDate'),
                    'leaveType' => (string) $requested->get('leaveType'),
                    'hours' => $this->floatOrNull($requested->get('hours')),
                    'requestedDays' => (float) $requested->get('requestedDays'),
                    'approverUserId' => $requested->get('approverUserId'),
                    'reason' => $requested->get('reason'),
                    'requestGroupId' => $requested->get('requestGroupId'),
                    'workflowRequestId' => $workflowId,
                ]);
                $consumed[$row->id] = true;
                // 消化の指定(申請時)。旧に指定の行があればその位置・値を使い、無ければ申請から合成する。
                $events[] = $this->designation(
                    $designated ?? $row,
                    $designated !== null ? 0 : 1,
                    $usageId(0),
                    $requestId,
                    $applicant,
                    $workflowId,
                    $requested,
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

            if ($kind === 'shared') {
                if ($state !== 'requested') {
                    throw new UnsupportedEventSequence($key, "申請の共有が状態 {$state} で起きた");
                }
                $events[] = RebuiltEvent::derived($row, 0, 'paid_leave_request.shared', $requestId, [
                    'workflowRequestId' => (string) $row->get('workflowRequestId'),
                ]);
                $consumed[$row->id] = true;
                $state = 'shared';

                continue;
            }

            if ($kind === 'approved') {
                if (! in_array($state, ['requested', 'shared'], true)) {
                    throw new UnsupportedEventSequence($key, "承認が状態 {$state} で起きた");
                }
                $approvedBy = $row->get('approvedByUserId');
                $approvedBy = $approvedBy !== null ? (string) $approvedBy : null;

                $events[] = RebuiltEvent::derived($row, 0, 'paid_leave_request.approved', $requestId, [
                    'approvedByUserId' => $approvedBy,
                    'userId' => $applicant,
                ]);
                // 承認は*.usedの有無にかかわらず消化を確定する(口座集約)。
                $events[] = RebuiltEvent::derived($row, 1, 'paid_leave_account.usage_confirmed', $applicant, [
                    'usageId' => $usageId($resubmits),
                    'confirmedByUserId' => $approvedBy,
                ], $row->id);
                $allocations = [];
                $sub = 2;
                foreach ($usageByApproval[$row->id] ?? [] as $used) {
                    $grantId = (string) $used->aggregateUuid;
                    $days = (float) $used->get('usedDays');
                    $events[] = RebuiltEvent::derived($row, $sub++, 'paid_leave_account.usage_allocated', $applicant, [
                        'usageId' => $usageId($resubmits),
                        'grantId' => $grantId,
                        'allocatedDays' => $days,
                    ], $used->id);
                    $allocations[] = ['grantId' => $grantId, 'days' => $days];
                    $consumed[$used->id] = true;
                }
                if (! $isWorkflow) {
                    $consumed[$row->id] = true;
                }
                $state = 'approved';
                $active = true;

                continue;
            }

            if ($kind === 'returned') {
                if (! in_array($state, ['requested', 'shared', 'approved'], true)) {
                    throw new UnsupportedEventSequence($key, "差戻しが状態 {$state} で起きた");
                }
                $returnedBy = (string) $row->get('returnedByUserId');
                $events[] = RebuiltEvent::derived($row, 0, 'paid_leave_request.returned', $requestId, [
                    'returnedByUserId' => $returnedBy,
                    'comment' => $row->get('comment'),
                    'userId' => $applicant,
                ]);
                $sub = 1;
                if ($active) {
                    $sub = $this->releaseAndCancel($events, $row, $sub, $usageId($resubmits), $allocations, $applicant, $returnedBy, self::RETURN_REASON);
                    $lastRelease = ['row' => $row, 'operatorUserId' => $returnedBy];
                }
                if (! $isWorkflow) {
                    $consumed[$row->id] = true;
                }
                $state = 'returned';
                $active = false;
                $allocations = [];

                continue;
            }

            if ($kind === 'resubmitted') {
                // 差戻し後のワークフローの再提出だけを再提出として扱う(それ以外のsubmittedは申請の内容を変えない)。
                if ($state !== 'returned') {
                    continue;
                }
                $resubmits++;
                $submittedBy = (string) $row->get('submittedByUserId');
                $approver = $row->get('approverUserId');
                $approver = $approver !== null ? (string) $approver : null;
                $events[] = RebuiltEvent::derived($row, 0, 'paid_leave_request.resubmitted', $requestId, [
                    'resubmittedByUserId' => $submittedBy,
                    'userId' => $applicant,
                    'targetDate' => (string) $requested->get('targetDate'),
                    'leaveType' => (string) $requested->get('leaveType'),
                    'hours' => $this->floatOrNull($requested->get('hours')),
                    'requestedDays' => (float) $requested->get('requestedDays'),
                    'approverUserId' => $approver,
                    'reason' => $requested->get('reason'),
                    'requestGroupId' => $requested->get('requestGroupId'),
                    'workflowRequestId' => $workflowId,
                ]);
                $events[] = $this->designation(
                    $row,
                    1,
                    $usageId($resubmits),
                    $requestId,
                    $applicant,
                    $workflowId,
                    $requested,
                    null,
                    $row->id,
                    $approver,
                );
                $state = 'requested';
                $active = true;

                continue;
            }

            if ($kind === 'cancelled') {
                if ($state === 'none' || $state === 'cancelled') {
                    throw new UnsupportedEventSequence($key, "取消が状態 {$state} で起きた");
                }
                $cancelledBy = (string) ($row->get('cancelledByUserId') ?? $row->get('rejectedByUserId') ?? '');
                $reason = $row->get('reason');
                $reason = $reason !== null ? (string) $reason : null;
                $events[] = RebuiltEvent::derived($row, 0, 'paid_leave_request.cancelled', $requestId, [
                    'cancelledByUserId' => $cancelledBy,
                    'reason' => $reason,
                    'userId' => $applicant,
                ]);
                $sub = 1;
                if ($active) {
                    $sub = $this->releaseAndCancel($events, $row, $sub, $usageId($resubmits), $allocations, $applicant, $cancelledBy, $reason ?? self::CANCEL_REASON);
                    $lastRelease = ['row' => $row, 'operatorUserId' => $cancelledBy];
                }
                if (! $isWorkflow) {
                    $consumed[$row->id] = true;
                }
                $state = 'cancelled';
                $active = false;
                $allocations = [];

                continue;
            }

            throw new UnsupportedEventSequence($key, "未対応の項目: {$kind}");
        }

        if ($state === 'none') {
            throw new UnsupportedEventSequence($key, '申請の作成が無い');
        }
        foreach ($usageRows as $row) {
            if ($row->eventClass === 'paid_leave.usage_reversed') {
                // 取消・差戻しの消化の取消は usage_cancelled に置き換える(有無にかかわらず)。
                $consumed[$row->id] = true;
            }
        }
        foreach ($usageRows as $row) {
            if (! isset($consumed[$row->id])) {
                throw new UnsupportedEventSequence($key, "充当されなかった消化の行: id={$row->id} {$row->eventClass}");
            }
        }
        foreach ($requestRows as $row) {
            if (! isset($consumed[$row->id]) && $row->eventClass !== 'paid_leave.usage_designated') {
                throw new UnsupportedEventSequence($key, "変換されなかった申請の行: id={$row->id} {$row->eventClass}");
            }
        }

        return new PaidLeaveRequestRebuild(
            events: $events,
            consumedIds: array_map('intval', array_keys($consumed)),
            finalState: $state,
            resubmitCount: $resubmits,
            userId: $applicant,
            targetDate: (string) $requested->get('targetDate'),
            lastRelease: $lastRelease,
            leaveType: $requested->get('leaveType') !== null ? (string) $requested->get('leaveType') : null,
        );
    }

    /**
     * cutover後の有給の1申請(申請の記録先が paid_leave_account.* だった時期。論点18 2.1)を、新しい申請の状態機械に変換する。
     *
     * 口座イベントを元に、申請集約のイベント(requested・shared・approved・returned・resubmitted・cancelled)を補う。
     * - usage_designated: 直前に requested、workflowRequestId があれば続けて shared(旧は申請時にその場で提出)。
     * - usage_confirmed: 直前に approved(approvedByUserId=confirmedByUserId)。
     * - usage_cancelled(差戻し中でないもの): 直前に cancelled。
     * - workflow_request.returned: 直後に returned と(有効な消化があれば)解放・usage_cancelled。
     * - workflow_request.rejected(申請中): 直後に cancelled と usage_cancelled(取消と同じ)。
     * - 差戻し後の workflow_request.submitted: resubmitted と、新しいusageIdの usage_designated。以後のusageIdを付け替える。
     * 乱数のusageId(最初の指定)はそのまま引き継ぎ、再提出のときだけ LeaveUsageId で新しく採番する。
     *
     * @param  list<StoredEventRow>  $accountRows  申請に属する paid_leave_account.usage_*(id順)
     * @param  list<StoredEventRow>  $workflowRows  申請のワークフローの workflow_request.* 行(id順。無ければ空)
     */
    public function rebuildFromAccount(string $requestId, array $accountRows, array $workflowRows): PaidLeaveRequestRebuild
    {
        $key = "paid_leave request {$requestId}";

        $items = [];
        foreach ($accountRows as $row) {
            $kind = match ($row->eventClass) {
                'paid_leave_account.usage_designated' => 'designated',
                'paid_leave_account.usage_confirmed' => 'confirmed',
                'paid_leave_account.usage_cancelled' => 'cancelled',
                'paid_leave_account.usage_allocated' => 'allocated',
                'paid_leave_account.usage_allocation_released' => 'released',
                default => throw new UnsupportedEventSequence($key, "想定外の口座の行: id={$row->id} {$row->eventClass}"),
            };
            $items[] = ['kind' => $kind, 'row' => $row, 'source' => 'account'];
        }
        foreach ($workflowRows as $row) {
            $kind = match ($row->eventClass) {
                'workflow_request.returned' => 'returned',
                'workflow_request.rejected' => 'rejected',
                'workflow_request.submitted' => 'submitted',
                default => null,
            };
            if ($kind !== null) {
                $items[] = ['kind' => $kind, 'row' => $row, 'source' => 'workflow'];
            }
        }
        usort($items, fn (array $a, array $b) => $a['row']->id <=> $b['row']->id);

        $events = [];
        $consumed = [];
        $state = 'none';
        $resubmits = 0;
        $active = false;
        $lastRelease = null;
        /** @var array<string, float> $allocations grantId => 充当日数 */
        $allocations = [];
        $originalUsageId = null;
        $applicant = null;
        $content = null;
        $firstDesignation = null;
        $workflowId = null;

        $usageId = function (int $k) use (&$originalUsageId, $requestId): string {
            return $k === 0 ? (string) $originalUsageId : LeaveUsageId::for(self::KIND, $requestId, $k);
        };
        $allocationList = function () use (&$allocations): array {
            $list = [];
            foreach ($allocations as $grantId => $days) {
                if ($days > 0.000001) {
                    $list[] = ['grantId' => (string) $grantId, 'days' => $days];
                }
            }

            return $list;
        };
        // 口座の行を写す(再提出より後は usageId を現在の消化記録に付け替える)。
        $carry = function (StoredEventRow $row, int $sub) use (&$events, &$consumed, &$resubmits, $usageId): void {
            $consumed[$row->id] = true;
            if ($resubmits === 0 || ($row->payload['usageId'] ?? null) === null) {
                $events[] = RebuiltEvent::passThrough($row);

                return;
            }
            $payload = $row->payload;
            $payload['usageId'] = $usageId($resubmits);
            $events[] = RebuiltEvent::derived($row, $sub, $row->eventClass, (string) $row->aggregateUuid, $payload);
        };

        foreach ($items as $item) {
            $kind = $item['kind'];
            /** @var StoredEventRow $row */
            $row = $item['row'];

            if ($kind === 'designated') {
                if ($state !== 'none') {
                    throw new UnsupportedEventSequence($key, '消化の指定が2回ある(cutover後は再提出でも指定しない前提)');
                }
                $applicant = (string) $row->aggregateUuid;
                $originalUsageId = (string) $row->get('usageId');
                $workflowId = $row->get('workflowRequestId');
                $workflowId = $workflowId !== null ? (string) $workflowId : null;
                $hours = $row->get('hours');
                $content = [
                    'userId' => $applicant,
                    'targetDate' => (string) $row->get('usedOn'),
                    'leaveType' => (string) $row->get('usageType'),
                    'hours' => $hours !== null ? (float) $hours : null,
                    'requestedDays' => (float) $row->get('usedDays'),
                    'approverUserId' => $row->get('approverUserId'),
                    'reason' => $row->get('reason'),
                    'requestGroupId' => $row->get('requestGroupId'),
                    'workflowRequestId' => $workflowId,
                ];
                $firstDesignation = $row;
                $events[] = RebuiltEvent::derived($row, -2, 'paid_leave_request.requested', $requestId, $content, $row->id);
                if ($workflowId !== null) {
                    $events[] = RebuiltEvent::derived($row, -1, 'paid_leave_request.shared', $requestId, [
                        'workflowRequestId' => $workflowId,
                    ], $row->id);
                    $state = 'shared';
                } else {
                    $state = 'requested';
                }
                $carry($row, 0);
                $active = true;

                continue;
            }

            if ($state === 'none') {
                throw new UnsupportedEventSequence($key, "消化の指定より前の行: id={$row->id} {$row->eventClass}");
            }

            if ($kind === 'confirmed') {
                if (! in_array($state, ['requested', 'shared'], true)) {
                    throw new UnsupportedEventSequence($key, "承認(消化の確定)が状態 {$state} で起きた");
                }
                $confirmedBy = $row->get('confirmedByUserId');
                $events[] = RebuiltEvent::derived($row, -1, 'paid_leave_request.approved', $requestId, [
                    'approvedByUserId' => $confirmedBy !== null ? (string) $confirmedBy : null,
                    'userId' => $applicant,
                ], $row->id);
                $carry($row, 0);
                $allocations = [];
                $state = 'approved';
                $active = true;

                continue;
            }

            if ($kind === 'allocated') {
                if ($state !== 'approved') {
                    throw new UnsupportedEventSequence($key, "充当が状態 {$state} で起きた");
                }
                $grantId = (string) $row->get('grantId');
                $allocations[$grantId] = ($allocations[$grantId] ?? 0.0) + (float) $row->get('allocatedDays');
                $carry($row, 0);

                continue;
            }

            if ($kind === 'released') {
                $grantId = (string) $row->get('grantId');
                $allocations[$grantId] = ($allocations[$grantId] ?? 0.0) - (float) $row->get('releasedDays');
                $carry($row, 0);

                continue;
            }

            if ($kind === 'cancelled') {
                if (in_array($state, ['cancelled', 'returned'], true)) {
                    throw new UnsupportedEventSequence($key, "消化の取消が状態 {$state} で起きた(差戻し中・取消済みの申請の消化の取消は扱えない)");
                }
                $cancelledBy = $row->get('cancelledByUserId');
                $reason = $row->get('reason');
                $events[] = RebuiltEvent::derived($row, -1, 'paid_leave_request.cancelled', $requestId, [
                    'cancelledByUserId' => $cancelledBy !== null ? (string) $cancelledBy : null,
                    'reason' => $reason !== null ? (string) $reason : null,
                    'userId' => $applicant,
                ], $row->id);
                $carry($row, 0);
                $state = 'cancelled';
                $active = false;
                $allocations = [];
                $lastRelease = ['row' => $row, 'operatorUserId' => (string) ($cancelledBy ?? $applicant)];

                continue;
            }

            if ($kind === 'returned') {
                if (! in_array($state, ['requested', 'shared', 'approved'], true)) {
                    throw new UnsupportedEventSequence($key, "差戻しが状態 {$state} で起きた");
                }
                $returnedBy = (string) $row->get('returnedByUserId');
                $events[] = RebuiltEvent::derived($row, 1, 'paid_leave_request.returned', $requestId, [
                    'returnedByUserId' => $returnedBy,
                    'comment' => $row->get('comment'),
                    'userId' => $applicant,
                ]);
                if ($active) {
                    $this->releaseAndCancel($events, $row, 2, $usageId($resubmits), $allocationList(), (string) $applicant, $returnedBy, self::RETURN_REASON);
                    $lastRelease = ['row' => $row, 'operatorUserId' => $returnedBy];
                }
                $state = 'returned';
                $active = false;
                $allocations = [];

                continue;
            }

            if ($kind === 'rejected') {
                if (! in_array($state, ['requested', 'shared'], true)) {
                    throw new UnsupportedEventSequence($key, "却下が状態 {$state} で起きた(申請中の却下だけ扱える)");
                }
                $rejectedBy = (string) $row->get('rejectedByUserId');
                $reason = $row->get('reason');
                $reason = $reason !== null ? (string) $reason : null;
                $events[] = RebuiltEvent::derived($row, 1, 'paid_leave_request.cancelled', $requestId, [
                    'cancelledByUserId' => $rejectedBy,
                    'reason' => $reason,
                    'userId' => $applicant,
                ]);
                $this->releaseAndCancel($events, $row, 2, $usageId($resubmits), $allocationList(), (string) $applicant, $rejectedBy, $reason ?? self::CANCEL_REASON);
                $lastRelease = ['row' => $row, 'operatorUserId' => $rejectedBy];
                $state = 'cancelled';
                $active = false;
                $allocations = [];

                continue;
            }

            if ($kind === 'submitted') {
                // 差戻し後のワークフローの再提出だけを再提出として扱う(最初の提出は shared で表している)。
                if ($state !== 'returned') {
                    continue;
                }
                $resubmits++;
                $approver = $row->get('approverUserId');
                $approver = $approver !== null ? (string) $approver : null;
                $events[] = RebuiltEvent::derived($row, 1, 'paid_leave_request.resubmitted', $requestId, [
                    'resubmittedByUserId' => (string) $row->get('submittedByUserId'),
                    'userId' => $applicant,
                    'targetDate' => $content['targetDate'],
                    'leaveType' => $content['leaveType'],
                    'hours' => $content['hours'],
                    'requestedDays' => $content['requestedDays'],
                    'approverUserId' => $approver,
                    'reason' => $content['reason'],
                    'requestGroupId' => $content['requestGroupId'],
                    'workflowRequestId' => $workflowId,
                ]);
                $designation = $firstDesignation->payload;
                $designation['usageId'] = $usageId($resubmits);
                $designation['approverUserId'] = $approver;
                $events[] = RebuiltEvent::derived($row, 2, 'paid_leave_account.usage_designated', (string) $applicant, $designation, $row->id);
                $state = 'requested';
                $active = true;
                $allocations = [];

                continue;
            }

            throw new UnsupportedEventSequence($key, "未対応の項目: {$kind}");
        }

        if ($state === 'none') {
            throw new UnsupportedEventSequence($key, '消化の指定(paid_leave_account.usage_designated)が無い');
        }

        return new PaidLeaveRequestRebuild(
            events: $events,
            consumedIds: array_map('intval', array_keys($consumed)),
            finalState: $state,
            resubmitCount: $resubmits,
            userId: $applicant,
            targetDate: $content['targetDate'],
            lastRelease: $lastRelease,
            leaveType: isset($content['leaveType']) ? (string) $content['leaveType'] : null,
        );
    }

    /**
     * 申請・ワークフローの行を、id順の変換対象の項目(kind)に並べる。
     * ワークフローの行は、申請側に同じ種類の行が無いときだけ項目にする(フォールバック)。
     *
     * @param  list<StoredEventRow>  $requestRows
     * @param  list<StoredEventRow>  $workflowRows
     * @return list<array{kind: string, row: StoredEventRow, source: string}>
     */
    private function timeline(array $requestRows, array $workflowRows): array
    {
        $has = fn (string $class): bool => collect($requestRows)->contains(fn (StoredEventRow $r) => $r->eventClass === $class);

        $items = [];
        $map = [
            'paid_leave.requested' => 'requested',
            'paid_leave.request_shared' => 'shared',
            'paid_leave.request_approved' => 'approved',
            'paid_leave.request_returned' => 'returned',
            'paid_leave.request_cancelled' => 'cancelled',
        ];
        foreach ($requestRows as $row) {
            if (isset($map[$row->eventClass])) {
                $items[] = ['kind' => $map[$row->eventClass], 'row' => $row, 'source' => 'request'];
            }
        }

        foreach ($workflowRows as $row) {
            $kind = match ($row->eventClass) {
                'workflow_request.approved' => $has('paid_leave.request_approved') ? null : 'approved',
                'workflow_request.returned' => $has('paid_leave.request_returned') ? null : 'returned',
                'workflow_request.cancelled' => $has('paid_leave.request_cancelled') ? null : 'cancelled',
                'workflow_request.rejected' => $has('paid_leave.request_cancelled') ? null : 'cancelled',
                'workflow_request.submitted' => 'resubmitted',
                default => null,
            };
            if ($kind !== null) {
                $items[] = ['kind' => $kind, 'row' => $row, 'source' => 'workflow'];
            }
        }

        usort($items, fn (array $a, array $b) => $a['row']->id <=> $b['row']->id);

        return $items;
    }

    /**
     * 消化の指定(口座集約の usage_designated)。旧に指定の行があれば、その値(消化日・日数・種類・勤怠日)を使う。
     *
     * @param  StoredEventRow|null  $old  旧の指定の行(無ければnull)
     */
    private function designation(
        StoredEventRow $anchor,
        int $sub,
        string $usageId,
        string $requestId,
        string $applicant,
        ?string $workflowId,
        StoredEventRow $requested,
        ?StoredEventRow $old,
        ?int $rebuiltFrom,
        ?string $approverOverride = null,
    ): RebuiltEvent {
        $approver = $approverOverride ?? $requested->get('approverUserId');
        $payload = [
            'usageId' => $usageId,
            'workflowRequestId' => $workflowId,
            'attendanceDayId' => $old?->get('attendanceDayId'),
            'usedOn' => (string) ($old?->get('usedOn') ?? $requested->get('targetDate')),
            'usedDays' => (float) ($old?->get('usedDays') ?? $requested->get('requestedDays')),
            'usageType' => (string) ($old?->get('usageType') ?? $requested->get('leaveType')),
            'paidLeaveRequestId' => $requestId,
            'approverUserId' => $approver !== null ? (string) $approver : null,
            'reason' => $requested->get('reason'),
            'requestGroupId' => $requested->get('requestGroupId'),
            'hours' => $this->floatOrNull($requested->get('hours')),
        ];

        return RebuiltEvent::derived($anchor, $sub, 'paid_leave_account.usage_designated', $applicant, $payload, $old === null ? $rebuiltFrom : null);
    }

    /**
     * 有効な消化を取り消す。充当済みの付与ごとに解放し、その後に usage_cancelled を置く。
     *
     * @param  list<RebuiltEvent>  $events  (参照で追記する)
     * @param  list<array{grantId: string, days: float}>  $allocations
     * @return int 次に使う subOrder
     */
    private function releaseAndCancel(
        array &$events,
        StoredEventRow $row,
        int $sub,
        string $usageId,
        array $allocations,
        string $applicant,
        string $cancelledBy,
        string $reason,
    ): int {
        foreach ($allocations as $allocation) {
            $events[] = RebuiltEvent::derived($row, $sub++, 'paid_leave_account.usage_allocation_released', $applicant, [
                'usageId' => $usageId,
                'grantId' => $allocation['grantId'],
                'releasedDays' => $allocation['days'],
            ], $row->id);
        }
        $events[] = RebuiltEvent::derived($row, $sub++, 'paid_leave_account.usage_cancelled', $applicant, [
            'usageId' => $usageId,
            'cancelledByUserId' => $cancelledBy,
            'reason' => $reason,
        ], $row->id);

        return $sub;
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

    private function floatOrNull(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
