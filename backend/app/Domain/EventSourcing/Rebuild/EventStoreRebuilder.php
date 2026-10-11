<?php

namespace App\Domain\EventSourcing\Rebuild;

/**
 * stored_events の旧イベント列(生の行)を、休暇の新しいイベント構成の行列に作り直す純粋な変換
 * (論点18 assets/event-rebuild-mapping.md 1・2章・6章)。DBには触れない(書き込みは EventStoreRebuildWriter)。
 *
 * 手順:
 * 1. 行を休暇の申請(有給の旧・cutover後、特別休暇、代休)・付与・cutover・ワークフロー・勤怠日・その他に分類する。
 *    扱えない種類は未対応として記録する。
 * 2. 申請ごと・付与ごと・cutover(利用者ごと)・勤怠日ごとに変換する。扱えない並びは未対応として記録し、続ける。
 *    勤怠日の変換は、休暇の変換結果(利用者・対象日・解除の操作)と、ReadModel(attendance_days)の現在値を入力にする。
 * 3. 変換で置き換えた行を除き、残りの行(休暇以外・ワークフロー等)は元の行をそのまま写す。
 * 4. 元の発生順(anchorId・subOrder)に並べ、id を1から振り直し、集約ごとに版を1から連番にする。
 *    休暇・勤怠以外の集約は版が変わらないことを確認し、変わったものは件数を報告する。
 *
 * 未対応が1件でもあれば rows は空にする(書き込ませない)。
 */
final class EventStoreRebuilder
{
    private const GRANT_CLASSES = [
        'paid_leave.granted',
        'paid_leave.grant_revoked',
        'paid_leave.warning_raised',
    ];

    private const CUTOVER_CLASS = 'paid_leave_account.migrated';

    /** 作り直しの対象外・未対応の種類(特別・代休の移行イベント、補正イベント)。 */
    private const UNSUPPORTED_CLASSES = [
        'special_leave_account.migrated',
        'compensatory_leave_account.migrated',
        'attendance_day.corrected',
    ];

    /** 接頭辞で未対応とする種類(作り直し後の新しい種類は本番に無い前提)。cutover後の paid_leave_account.* は対応する。 */
    private const UNSUPPORTED_PREFIXES = [
        'paid_leave_request.',
        'special_leave_account.',
        'compensatory_leave_account.',
    ];

    /** 作り直し後に残ってはいけない旧の種類(論点18 4章)。作り直し後の表の検査に使う。 */
    public const LEGACY_CLASSES = [
        'paid_leave.granted',
        'paid_leave.requested',
        'paid_leave.request_approved',
        'paid_leave.request_returned',
        'paid_leave.request_cancelled',
        'paid_leave.request_shared',
        'paid_leave.usage_designated',
        'paid_leave.used',
        'paid_leave.usage_reversed',
        'paid_leave.warning_raised',
        'paid_leave.grant_revoked',
        'special_leave.granted',
        'special_leave.grant_revoked',
        'special_leave.usage_designated',
        'special_leave.used',
        'special_leave.usage_reversed',
        'compensatory_leave.grant_synced',
        'compensatory_leave.manually_granted',
        'compensatory_leave.grant_removed',
        'compensatory_leave.grant_confirmed',
        'compensatory_leave.grant_cancelled',
        'compensatory_leave.usage_designated',
        'compensatory_leave.used',
        'compensatory_leave.usage_reversed',
        'paid_leave_account.migrated',
        'special_leave_account.migrated',
        'compensatory_leave_account.migrated',
        'paid_leave_request.migrated',
        'attendance_day.corrected',
    ];

    public function __construct(
        private readonly PaidLeaveRequestRebuilder $paidRequests = new PaidLeaveRequestRebuilder,
        private readonly PaidLeaveGrantRebuilder $grants = new PaidLeaveGrantRebuilder,
        private readonly LeaveRequestRebuilder $leaveRequests = new LeaveRequestRebuilder,
        private readonly LeaveAccountGrantRebuilder $leaveGrants = new LeaveAccountGrantRebuilder,
        private readonly AttendanceRebuilder $attendance = new AttendanceRebuilder,
    ) {}

    /**
     * @param  list<StoredEventRow>  $rows  stored_events の全行(id順でなくてもよい)
     * @param  array<string, AttendanceDayCurrent>  $attendanceDays  ReadModel(attendance_days)の現在値(勤怠日ID => 現在値)。勤怠の変換(2.4)の入力
     */
    public function rebuild(array $rows, array $attendanceDays = []): RebuildResult
    {
        usort($rows, fn (StoredEventRow $a, StoredEventRow $b) => $a->id <=> $b->id);

        $unsupported = [];
        $byClassBefore = [];
        $paidRequestRows = [];
        $usageRows = [];
        $usageRowsByGrant = [];
        $grantRows = [];
        $workflowRows = [];
        $workflowByPaidRequest = [];
        $accountUsageRows = [];
        $cutoverRows = [];
        $attendanceRows = [];
        /** @var array<string, array<string, list<StoredEventRow>>> $leaveRequestRows kind => 申請ID => 申請集約の行 */
        $leaveRequestRows = [];
        /** @var array<string, array<string, list<StoredEventRow>>> $leaveUsageRows kind => 申請ID => 付与集約の *.used・*.usage_reversed */
        $leaveUsageRows = [];
        /** @var array<string, array<string, list<StoredEventRow>>> $leaveUsageRowsByGrant kind => 付与ID => 消化の行 */
        $leaveUsageRowsByGrant = [];
        /** @var array<string, array<string, list<StoredEventRow>>> $leaveGrantRows kind => 付与ID => 付与集約の行 */
        $leaveGrantRows = [];

        foreach ($rows as $row) {
            $byClassBefore[$row->eventClass] = ($byClassBefore[$row->eventClass] ?? 0) + 1;
            $class = $row->eventClass;

            if ($class === self::CUTOVER_CLASS) {
                $cutoverRows[] = $row;
            } elseif ($this->isUnsupported($class)) {
                $unsupported[] = $this->issue((string) $row->aggregateUuid, "未対応の種類: {$class} (id={$row->id})");
            } elseif (str_starts_with($class, 'workflow_request.')) {
                $workflowRows[(string) $row->aggregateUuid][] = $row;
                if ($class === 'workflow_request.drafted' && $row->get('subjectType') === 'paid_leave_request' && $row->get('subjectId') !== null) {
                    $workflowByPaidRequest[(string) $row->get('subjectId')] = (string) $row->aggregateUuid;
                }
            } elseif (in_array($class, PaidLeaveRequestRebuilder::REQUEST_CLASSES, true)) {
                $paidRequestRows[(string) $row->aggregateUuid][] = $row;
            } elseif (in_array($class, PaidLeaveRequestRebuilder::USAGE_CLASSES, true)) {
                $requestId = $row->get('paidLeaveRequestId');
                if ($requestId === null) {
                    $unsupported[] = $this->issue((string) $row->aggregateUuid, "申請IDの無い消化の行: id={$row->id}");

                    continue;
                }
                $usageRows[(string) $requestId][] = $row;
                $usageRowsByGrant[(string) $row->aggregateUuid][] = $row;
            } elseif (in_array($class, PaidLeaveRequestRebuilder::ACCOUNT_USAGE_CLASSES, true)) {
                $accountUsageRows[] = $row;
            } elseif (in_array($class, self::GRANT_CLASSES, true)) {
                $grantRows[(string) $row->aggregateUuid][] = $row;
            } elseif ($this->classifyLeaveKind($row, $leaveRequestRows, $leaveUsageRows, $leaveUsageRowsByGrant, $leaveGrantRows, $unsupported)) {
                continue;
            } elseif (str_starts_with($class, 'attendance_day.') && $row->aggregateUuid !== null) {
                $attendanceRows[(string) $row->aggregateUuid][] = $row;
            }
        }

        /** @var array<int, true> $consumed */
        $consumed = [];
        /** @var list<RebuiltEvent> $converted */
        $converted = [];
        $paidRequestSummary = [];
        $leaveRequestSummary = [LeaveRequestRebuilder::SPECIAL => [], LeaveRequestRebuilder::COMPENSATORY => []];
        /** @var list<PaidLeaveRequestRebuild> $requestResults 休暇の申請ごとの変換結果(勤怠の変換の入力) */
        $requestResults = [];
        $grantStates = [];
        $cutoverSummary = [];
        $handledRequests = [];

        $absorb = function (PaidLeaveRequestRebuild $result) use (&$converted, &$consumed, &$requestResults): void {
            array_push($converted, ...$result->events);
            foreach ($result->consumedIds as $id) {
                $consumed[$id] = true;
            }
            $requestResults[] = $result;
        };

        // 有給の申請(旧: paid_leave.*)。
        foreach ($paidRequestRows as $requestId => $requestRowsOfOne) {
            try {
                $shared = null;
                foreach ($requestRowsOfOne as $row) {
                    if ($row->eventClass === 'paid_leave.request_shared') {
                        $shared = $row;
                    }
                }
                $workflowId = $shared?->get('workflowRequestId');
                $workflow = $workflowId !== null ? ($workflowRows[(string) $workflowId] ?? []) : [];

                $result = $this->paidRequests->rebuild(
                    (string) $requestId,
                    $requestRowsOfOne,
                    $usageRows[(string) $requestId] ?? [],
                    $workflow,
                );
                $absorb($result);
                $handledRequests[(string) $requestId] = true;
                $paidRequestSummary[] = [
                    'request_id' => (string) $requestId,
                    'final_state' => $result->finalState,
                    'resubmit_count' => $result->resubmitCount,
                ];
            } catch (UnsupportedEventSequence $e) {
                $unsupported[] = $this->issue($e->key, $e->getMessage());
            }
        }

        foreach ($usageRows as $requestId => $rowsOfRequest) {
            if (! isset($handledRequests[(string) $requestId])) {
                $unsupported[] = $this->issue((string) $requestId, '申請の作成が無い消化の行がある');
            }
        }

        // 有給の申請(cutover後: paid_leave_account.usage_*)。
        [$accountByRequest, $accountIssues] = $this->groupAccountUsageRows($accountUsageRows, $workflowByPaidRequest);
        array_push($unsupported, ...$accountIssues);
        foreach ($accountByRequest as $requestId => $accountRowsOfOne) {
            if (isset($handledRequests[(string) $requestId])) {
                $unsupported[] = $this->issue((string) $requestId, '旧の申請(paid_leave.*)とcutover後の口座の消化(paid_leave_account.usage_*)が混在している');

                continue;
            }
            try {
                $workflowId = null;
                foreach ($accountRowsOfOne as $row) {
                    if ($row->eventClass === 'paid_leave_account.usage_designated') {
                        $workflowId = $row->get('workflowRequestId');
                    }
                }
                $workflowId ??= $workflowByPaidRequest[(string) $requestId] ?? null;
                $workflow = $workflowId !== null ? ($workflowRows[(string) $workflowId] ?? []) : [];

                $result = $this->paidRequests->rebuildFromAccount((string) $requestId, $accountRowsOfOne, $workflow);
                $absorb($result);
                $handledRequests[(string) $requestId] = true;
                $paidRequestSummary[] = [
                    'request_id' => (string) $requestId,
                    'final_state' => $result->finalState,
                    'resubmit_count' => $result->resubmitCount,
                ];
            } catch (UnsupportedEventSequence $e) {
                $unsupported[] = $this->issue($e->key, $e->getMessage());
            }
        }

        // 特別休暇・代休の申請。
        foreach ([LeaveRequestRebuilder::SPECIAL, LeaveRequestRebuilder::COMPENSATORY] as $kind) {
            $handledLeave = [];
            foreach ($leaveRequestRows[$kind] ?? [] as $requestId => $requestRowsOfOne) {
                try {
                    $result = $this->leaveRequests->rebuild($kind, (string) $requestId, $requestRowsOfOne, $leaveUsageRows[$kind][$requestId] ?? []);
                    $absorb($result);
                    $handledLeave[(string) $requestId] = true;
                    $leaveRequestSummary[$kind][] = [
                        'request_id' => (string) $requestId,
                        'final_state' => $result->finalState,
                        'resubmit_count' => $result->resubmitCount,
                    ];
                } catch (UnsupportedEventSequence $e) {
                    $unsupported[] = $this->issue($e->key, $e->getMessage());
                }
            }
            foreach ($leaveUsageRows[$kind] ?? [] as $requestId => $rowsOfRequest) {
                if (! isset($handledLeave[(string) $requestId])) {
                    $unsupported[] = $this->issue((string) $requestId, "申請の作成が無い消化の行がある({$kind})");
                }
            }
        }

        // 有給の付与。
        foreach ($grantRows as $grantId => $rowsOfGrant) {
            $granted = null;
            foreach ($rowsOfGrant as $row) {
                if ($row->eventClass === 'paid_leave.granted') {
                    $granted = $row;
                }
            }
            if ($granted === null) {
                $unsupported[] = $this->issue((string) $grantId, '付与の作成(paid_leave.granted)が無い');

                continue;
            }
            $userId = (string) $granted->get('userId');
            $used = 0.0;
            $reversed = 0.0;
            foreach ($usageRowsByGrant[(string) $grantId] ?? [] as $usage) {
                if ($usage->eventClass === 'paid_leave.used') {
                    $used += (float) $usage->get('usedDays');
                } else {
                    $reversed += (float) $usage->get('usedDays');
                }
            }
            $grantStates[(string) $grantId] = ['granted' => $granted, 'userId' => $userId, 'used' => $used, 'reversed' => $reversed];

            try {
                array_push($converted, ...$this->grants->convertGrant((string) $grantId, $userId, $rowsOfGrant));
                foreach ($rowsOfGrant as $row) {
                    $consumed[$row->id] = true;
                }
            } catch (UnsupportedEventSequence $e) {
                $unsupported[] = $this->issue($e->key, $e->getMessage());
            }
        }

        foreach ($usageRowsByGrant as $grantId => $rowsOfGrant) {
            if (! isset($grantStates[(string) $grantId])) {
                $unsupported[] = $this->issue((string) $grantId, '付与の作成が無い消化の行がある');
            }
        }

        // 特別休暇・代休の付与。
        $leaveGrantCount = [LeaveRequestRebuilder::SPECIAL => 0, LeaveRequestRebuilder::COMPENSATORY => 0];
        foreach ([LeaveRequestRebuilder::SPECIAL, LeaveRequestRebuilder::COMPENSATORY] as $kind) {
            $knownGrants = [];
            foreach ($leaveGrantRows[$kind] ?? [] as $grantId => $rowsOfGrant) {
                try {
                    $outcome = $kind === LeaveRequestRebuilder::SPECIAL
                        ? $this->leaveGrants->convertSpecial((string) $grantId, $rowsOfGrant)
                        : $this->leaveGrants->convertCompensatory((string) $grantId, $rowsOfGrant);
                    array_push($converted, ...$outcome['events']);
                    foreach ($rowsOfGrant as $row) {
                        $consumed[$row->id] = true;
                    }
                    $knownGrants[(string) $grantId] = true;
                    $leaveGrantCount[$kind]++;
                } catch (UnsupportedEventSequence $e) {
                    $unsupported[] = $this->issue($e->key, $e->getMessage());
                }
            }
            foreach ($leaveUsageRowsByGrant[$kind] ?? [] as $grantId => $rowsOfGrant) {
                if (! isset($knownGrants[(string) $grantId])) {
                    $unsupported[] = $this->issue((string) $grantId, "付与の作成が無い消化の行がある({$kind})");
                }
            }
        }

        foreach ($cutoverRows as $cutover) {
            try {
                $outcome = $this->grants->reconcileCutover($cutover, $grantStates);
                array_push($converted, ...$outcome['events']);
                $cutoverSummary = array_merge($cutoverSummary, $outcome['outcomes']);
                $consumed[$cutover->id] = true;
            } catch (UnsupportedEventSequence $e) {
                $unsupported[] = $this->issue($e->key, $e->getMessage());
            }
        }

        // 勤怠日(論点18 2.4・2.5)。休暇の変換結果から、利用者・対象日ごとの休暇を引けるようにする。
        $leavesByDay = [];
        foreach ($requestResults as $result) {
            if ($result->userId === null || $result->targetDate === null) {
                continue;
            }
            $leavesByDay[$result->userId.'|'.substr($result->targetDate, 0, 10)][] = [
                'userId' => $result->userId,
                'finalState' => $result->finalState,
                'lastRelease' => $result->lastRelease,
            ];
        }
        $attendanceSummary = ['days' => 0, 'created_added' => 0, 'deleted_added' => 0, 'work_type_rewritten' => 0, 'null_items_filled' => 0];
        foreach ($attendanceRows as $dayId => $dayRows) {
            $current = $attendanceDays[(string) $dayId] ?? null;
            $identity = AttendanceRebuilder::identity($dayRows, $current);
            $leaves = $identity['userId'] !== null && $identity['workDate'] !== null
                ? ($leavesByDay[$identity['userId'].'|'.$identity['workDate']] ?? [])
                : [];
            try {
                $result = $this->attendance->rebuild((string) $dayId, $dayRows, $current, $leaves);
                array_push($converted, ...$result->events);
                foreach ($result->consumedIds as $id) {
                    $consumed[$id] = true;
                }
                $attendanceSummary['days']++;
                $attendanceSummary['created_added'] += $result->createdAdded ? 1 : 0;
                $attendanceSummary['deleted_added'] += $result->deletedAdded ? 1 : 0;
                $attendanceSummary['work_type_rewritten'] += $result->workTypeRewritten;
                $attendanceSummary['null_items_filled'] += $result->nullItemsFilled;
            } catch (UnsupportedEventSequence $e) {
                $unsupported[] = $this->issue($e->key, $e->getMessage());
            }
        }

        $report = [
            'by_class_before' => $byClassBefore,
            'paid_requests' => $paidRequestSummary,
            'special_requests' => $leaveRequestSummary[LeaveRequestRebuilder::SPECIAL],
            'compensatory_requests' => $leaveRequestSummary[LeaveRequestRebuilder::COMPENSATORY],
            'leave_grants' => $leaveGrantCount,
            'attendance' => $attendanceSummary,
            'cutover' => $cutoverSummary,
        ];

        if ($unsupported !== []) {
            return RebuildResult::failed(unsupported: $unsupported, report: $report);
        }

        $events = [];
        $leaveAggregates = [];
        foreach ($converted as $event) {
            $events[] = $event;
            if ($event->aggregateUuid !== null) {
                $leaveAggregates[$event->aggregateUuid] = true;
            }
        }
        foreach ($rows as $row) {
            if (! isset($consumed[$row->id])) {
                $events[] = RebuiltEvent::passThrough($row);
            }
        }

        return $this->renumber($events, $report, $leaveAggregates);
    }

    /**
     * 特別休暇・代休の旧イベントの行を、申請・消化・付与のどれかに分類する。該当しなければfalse。
     *
     * @param  array<string, array<string, list<StoredEventRow>>>  $leaveRequestRows
     * @param  array<string, array<string, list<StoredEventRow>>>  $leaveUsageRows
     * @param  array<string, array<string, list<StoredEventRow>>>  $leaveUsageRowsByGrant
     * @param  array<string, array<string, list<StoredEventRow>>>  $leaveGrantRows
     * @param  list<array{key: string, reason: string}>  $unsupported
     */
    private function classifyLeaveKind(
        StoredEventRow $row,
        array &$leaveRequestRows,
        array &$leaveUsageRows,
        array &$leaveUsageRowsByGrant,
        array &$leaveGrantRows,
        array &$unsupported,
    ): bool {
        foreach ([LeaveRequestRebuilder::SPECIAL, LeaveRequestRebuilder::COMPENSATORY] as $kind) {
            $grantClasses = $kind === LeaveRequestRebuilder::SPECIAL ? LeaveAccountGrantRebuilder::SPECIAL_CLASSES : LeaveAccountGrantRebuilder::COMPENSATORY_CLASSES;

            if (in_array($row->eventClass, LeaveRequestRebuilder::requestClasses($kind), true)) {
                $leaveRequestRows[$kind][(string) $row->aggregateUuid][] = $row;

                return true;
            }
            if (in_array($row->eventClass, LeaveRequestRebuilder::usageClasses($kind), true)) {
                $requestId = $row->get(LeaveRequestRebuilder::usageRequestKey($kind));
                if ($requestId === null) {
                    $unsupported[] = $this->issue((string) $row->aggregateUuid, "申請IDの無い消化の行: id={$row->id} ({$kind})");

                    return true;
                }
                $leaveUsageRows[$kind][(string) $requestId][] = $row;
                $leaveUsageRowsByGrant[$kind][(string) $row->aggregateUuid][] = $row;

                return true;
            }
            if (in_array($row->eventClass, $grantClasses, true)) {
                $leaveGrantRows[$kind][(string) $row->aggregateUuid][] = $row;

                return true;
            }
        }

        return false;
    }

    /**
     * cutover後の paid_leave_account.usage_* の行を、申請ID(最初の usage_designated の paidLeaveRequestId。無ければワークフローの
     * subjectId)ごとにまとめる。他の行は usageId で、その消化を指定した申請に結び付ける。
     *
     * @param  list<StoredEventRow>  $accountRows  id順
     * @param  array<string, string>  $workflowByPaidRequest  有給申請ID => ワークフローID(参照のみ。逆引きに使う)
     * @return array{0: array<string, list<StoredEventRow>>, 1: list<array{key: string, reason: string}>}
     */
    private function groupAccountUsageRows(array $accountRows, array $workflowByPaidRequest): array
    {
        $issues = [];
        $requestByWorkflow = array_flip($workflowByPaidRequest);
        $requestByUsage = [];
        foreach ($accountRows as $row) {
            if ($row->eventClass !== 'paid_leave_account.usage_designated') {
                continue;
            }
            $requestId = $row->get('paidLeaveRequestId');
            if ($requestId === null && $row->get('workflowRequestId') !== null) {
                $requestId = $requestByWorkflow[(string) $row->get('workflowRequestId')] ?? null;
            }
            if ($requestId === null) {
                $issues[] = $this->issue((string) $row->get('usageId'), "申請に結び付かない消化の指定(paidLeaveRequestIdもワークフローのsubjectIdも無い): id={$row->id}");

                continue;
            }
            $requestByUsage[(string) $row->get('usageId')] = (string) $requestId;
        }

        $byRequest = [];
        foreach ($accountRows as $row) {
            $usageId = (string) $row->get('usageId');
            if (! isset($requestByUsage[$usageId])) {
                if ($row->eventClass !== 'paid_leave_account.usage_designated') {
                    $issues[] = $this->issue($usageId, "消化の指定が無い消化の行: id={$row->id} {$row->eventClass}");
                }

                continue;
            }
            $byRequest[$requestByUsage[$usageId]][] = $row;
        }

        return [$byRequest, $issues];
    }

    /**
     * @param  list<RebuiltEvent>  $events
     * @param  array<string, mixed>  $report  by_class_before ほか(by_class_after・versions_changed_outside_leave を足して返す)
     * @param  array<string, true>  $leaveAggregates
     */
    private function renumber(array $events, array $report, array $leaveAggregates): RebuildResult
    {
        $indexed = [];
        foreach ($events as $i => $event) {
            $indexed[] = [$event, $i];
        }
        usort($indexed, function (array $a, array $b): int {
            return [$a[0]->anchorId, $a[0]->subOrder, $a[1]] <=> [$b[0]->anchorId, $b[0]->subOrder, $b[1]];
        });

        $versions = [];
        $rows = [];
        $versionChangedOutside = 0;
        $byClassAfter = [];

        foreach ($indexed as $position => [$event, $_]) {
            $id = $position + 1;
            $version = null;
            if ($event->aggregateUuid !== null) {
                $version = ($versions[$event->aggregateUuid] ?? 0) + 1;
                $versions[$event->aggregateUuid] = $version;
            }

            if ($event->sourceVersion !== null && $version !== $event->sourceVersion && ! isset($leaveAggregates[(string) $event->aggregateUuid])) {
                $versionChangedOutside++;
            }

            $meta = $event->meta;
            if ($version !== null) {
                $meta['aggregate-root-version'] = $version;
            }
            if ($event->rebuiltFrom !== null) {
                $meta['rebuilt_from'] = $event->rebuiltFrom;
            }

            $rows[] = [
                'id' => $id,
                'aggregate_uuid' => $event->aggregateUuid,
                'aggregate_version' => $version,
                'event_version' => 1,
                'event_class' => $event->eventClass,
                'event_properties' => $event->payload,
                'meta_data' => $meta,
                'created_at' => $event->createdAt,
            ];
            $byClassAfter[$event->eventClass] = ($byClassAfter[$event->eventClass] ?? 0) + 1;
        }

        return RebuildResult::succeeded(
            rows: $rows,
            report: $report + [
                'by_class_after' => $byClassAfter,
                'versions_changed_outside_leave' => $versionChangedOutside,
            ],
        );
    }

    private function isUnsupported(string $class): bool
    {
        if (in_array($class, self::UNSUPPORTED_CLASSES, true)) {
            return true;
        }
        foreach (self::UNSUPPORTED_PREFIXES as $prefix) {
            if (str_starts_with($class, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{key: string, reason: string} */
    private function issue(string $key, string $reason): array
    {
        return ['key' => $key, 'reason' => $reason];
    }
}
