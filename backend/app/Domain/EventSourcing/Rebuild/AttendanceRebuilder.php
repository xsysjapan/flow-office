<?php

namespace App\Domain\EventSourcing\Rebuild;

/**
 * 勤怠日(集約)1件ぶんの旧イベント列の変換(論点18 assets/event-rebuild-mapping.md 2.4・2.5)。
 *
 * - attendance_day.created が無い勤怠日(行を作るイベントが最初に無い日)に、最初のイベントの直前に created を補う。
 *   項目は ReadModel(attendance_days)の現在値から作る(source・status・createdByUserId・reason の規則は補う箇所のコメント参照)。
 * - created / edited の workType が休暇値(paid_leave_ 等で始まる値)なら、同じ勤怠日の休暇値でない直前の値(無ければnull)に書き換える。
 * - 全休の休暇申請がある日の edited で、status=clocked_out なのに実績(actualStartAt・actualEndAt)がともにnullなら、status を直前の状態に書き換える(決定9-2)。
 * - 休暇の解除で空になった勤怠日の deleted は、後続のイベントが無いときだけ、解除の直後に置く。
 * - calculated・daily_calculation_adjusted の既定値null項目(userId・workDate・dayClassification・workMinutes)を補完する。
 *
 * 扱えない並び(ReadModelに現在値が無く created を補えない、補完元が無い項目)は UnsupportedEventSequence を投げる。
 */
final class AttendanceRebuilder
{
    public const LEAVE_WORK_TYPE_PREFIXES = ['paid_leave_', 'special_leave_', 'compensatory_leave_'];

    /** 全休の休暇の種類(PaidLeaveType::FULL)。 */
    public const FULL_LEAVE_TYPE = 'full';

    public const CREATED_REASON = '既存データの引き継ぎ';

    public const DELETED_REASON = '休暇の差戻し・取消に伴い、休暇のために作成した勤怠日を削除';

    public const PUNCH_LOG_ACTION = 'leave_punches';

    /** deleted を置くときの subOrder(同じ位置に置く他の行より後ろ)。 */
    private const DELETED_SUB_ORDER = 1000;

    /** 勤怠日の行を作るイベント。これが最初にあれば created は補わない。 */
    private const ROW_CREATING_CLASSES = [
        'attendance_day.created',
        'attendance_day.live_status_synced',
        'attendance_day.synced_from_punches',
    ];

    public static function isLeaveWorkType(?string $workType): bool
    {
        if ($workType === null) {
            return false;
        }
        foreach (self::LEAVE_WORK_TYPE_PREFIXES as $prefix) {
            if (str_starts_with($workType, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 勤怠日の利用者IDと勤務日(Y-m-d)。created のpayload → 他のイベントのpayload → ReadModel の順に探す。
     *
     * @param  list<StoredEventRow>  $rows
     * @return array{userId: ?string, workDate: ?string}
     */
    public static function identity(array $rows, ?AttendanceDayCurrent $current): array
    {
        $found = ['userId' => null, 'workDate' => null];
        foreach ($rows as $row) {
            if ($row->eventClass === 'attendance_day.created') {
                $found['userId'] ??= $row->get('userId') !== null ? (string) $row->get('userId') : null;
                $found['workDate'] ??= $row->get('workDate') !== null ? self::date((string) $row->get('workDate')) : null;
            }
        }
        if ($current !== null) {
            $found['userId'] ??= $current->userId;
            $found['workDate'] ??= self::date($current->workDate);
        }
        foreach ($rows as $row) {
            $found['userId'] ??= $row->get('userId') !== null ? (string) $row->get('userId') : null;
            $found['workDate'] ??= $row->get('workDate') !== null ? self::date((string) $row->get('workDate')) : null;
        }

        return $found;
    }

    /**
     * @param  list<StoredEventRow>  $rows  勤怠日(集約=$dayId)の attendance_day.* 行(id順)
     * @param  list<array{userId: string, finalState: string, leaveType?: ?string, lastRelease: ?array{row: StoredEventRow, operatorUserId: string}}>  $leaves  その日(利用者・勤務日)の休暇申請の変換結果(finalState・lastRelease は PaidLeaveRequestRebuild と同じ)
     */
    public function rebuild(string $dayId, array $rows, ?AttendanceDayCurrent $current, array $leaves = []): AttendanceRebuild
    {
        $key = "attendance_day {$dayId}";
        if ($rows === []) {
            throw new UnsupportedEventSequence($key, '勤怠日のイベントが無い');
        }

        $identity = self::identity($rows, $current);
        $first = $rows[0];

        $events = [];
        $consumed = [];
        $lastNonLeaveWorkType = null;
        $createdAdded = false;
        $createdSource = null;
        $rewritten = 0;
        $filled = 0;
        $statusRewritten = [];
        // 直前の状態(status)。無ければ not_started。
        $previousStatus = 'not_started';
        $hasFullLeave = false;
        foreach ($leaves as $leave) {
            if (($leave['leaveType'] ?? null) === self::FULL_LEAVE_TYPE) {
                $hasFullLeave = true;
            }
        }

        if (! in_array($first->eventClass, self::ROW_CREATING_CLASSES, true)) {
            if ($current === null) {
                throw new UnsupportedEventSequence($key, 'ReadModel(attendance_days)に現在値が無く、attendance_day.created を補えない');
            }
            $hasLeave = $leaves !== [];
            $untouched = ! $current->hasActual() && ! $current->hasPunch;
            $offset = $current->utcOffsetMinutes;
            $workType = self::isLeaveWorkType($current->workType) ? null : $current->workType;
            $createdSource = $hasLeave && $untouched ? 'leave' : $current->source;
            $lastNonLeaveWorkType = $workType;
            $previousStatus = $untouched ? 'not_started' : (string) $current->status;

            $events[] = RebuiltEvent::derived($first, -1, 'attendance_day.created', $dayId, [
                'userId' => $current->userId,
                'workDate' => self::date($current->workDate),
                'calendarEntryId' => $current->calendarEntryId,
                // 休暇の処理が直接 clocked_out にした全休の日は、実績から決まる値(実績・打刻が無ければ not_started)にする。
                'status' => $untouched ? 'not_started' : $current->status,
                'source' => $createdSource,
                'utcOffsetMinutes' => $offset,
                'actualStartAt' => self::iso($current->actualStartAt, $offset),
                'actualEndAt' => self::iso($current->actualEndAt, $offset),
                'workType' => $workType,
                'workLocationType' => $current->workLocationType,
                'note' => $current->note,
                'breaks' => array_map(fn (array $b) => [
                    'start' => self::iso($b['start'], $offset),
                    'end' => self::iso($b['end'], $offset),
                ], $current->breaks),
                'leaveSegments' => array_map(fn (array $s) => [
                    'start' => self::iso($s['start'], $offset),
                    'end' => self::iso($s['end'], $offset),
                    'note' => $s['note'] ?? null,
                ], $current->leaveSegments),
                'reason' => self::CREATED_REASON,
                'createdByUserId' => (string) ($leaves[0]['userId'] ?? $current->userId),
            ], $first->id);
            $createdAdded = true;
        }

        $lastCalculation = null;
        $hasDeleted = false;
        $hasAdjusted = false;
        foreach ($rows as $row) {
            $payload = $row->payload;

            if (in_array($row->eventClass, ['attendance_day.created', 'attendance_day.edited'], true)) {
                $workType = $row->get('workType');
                if (self::isLeaveWorkType($workType)) {
                    $payload['workType'] = $lastNonLeaveWorkType;
                    $rewritten++;
                } else {
                    $lastNonLeaveWorkType = $workType !== null ? (string) $workType : null;
                }
                if ($row->eventClass === 'attendance_day.created') {
                    $createdSource = (string) $row->get('source');
                }
                // 全休の日の edited が、実績が無いのに status=clocked_out を持つとき、直前の状態に戻す(論点19 決定9-2)。
                if ($row->eventClass === 'attendance_day.edited' && $hasFullLeave
                    && $row->get('status') === 'clocked_out'
                    && $row->get('actualStartAt') === null && $row->get('actualEndAt') === null) {
                    $payload['status'] = $previousStatus;
                    $statusRewritten[] = [
                        'dayId' => $dayId,
                        'workDate' => $row->get('workDate') !== null ? self::date((string) $row->get('workDate')) : $identity['workDate'],
                        'eventId' => $row->id,
                    ];
                } elseif ($row->get('status') !== null) {
                    $previousStatus = (string) $row->get('status');
                }
            } elseif ($row->eventClass === 'attendance_day.live_status_synced') {
                if ($row->get('status') !== null) {
                    $previousStatus = (string) $row->get('status');
                }
            } elseif ($row->eventClass === 'attendance_day.calculated') {
                $payload = $this->fillIdentity($payload, $identity, $key, $row, $filled);
                $calculation = $row->get('calculation');
                $lastCalculation = is_array($calculation) ? $calculation : null;
            } elseif ($row->eventClass === 'attendance_day.daily_calculation_adjusted') {
                $hasAdjusted = true;
                $payload = $this->fillIdentity($payload, $identity, $key, $row, $filled);
                if (($payload['dayClassification'] ?? null) === null) {
                    $classification = $lastCalculation['day_classification'] ?? $current?->dayClassification;
                    if (is_string($classification)) {
                        $payload['dayClassification'] = $classification;
                        $filled++;
                    }
                }
                if (($payload['workMinutes'] ?? null) === null) {
                    if (! isset($lastCalculation['work_minutes'])) {
                        throw new UnsupportedEventSequence($key, "日次計算の手動調整(id={$row->id})の実労働分を補う元(直前の attendance_day.calculated)が無い");
                    }
                    $payload['workMinutes'] = (int) $lastCalculation['work_minutes'];
                    $filled++;
                }
            } elseif ($row->eventClass === 'attendance_day.deleted') {
                $hasDeleted = true;
            } elseif ($row->eventClass === 'attendance_day.weekly_overtime_allocated') {
                $hasAdjusted = true;
            }

            if ($payload !== $row->payload) {
                $events[] = RebuiltEvent::derived($row, 0, $row->eventClass, $dayId, $payload);
                $consumed[$row->id] = true;
            }
        }

        $deletedAdded = false;
        $release = $this->releaseForEmptyDay($rows, $current, $leaves, $createdSource, $hasDeleted, $hasAdjusted);
        if ($release !== null && $identity['userId'] !== null && $identity['workDate'] !== null) {
            $events[] = RebuiltEvent::derived($release['row'], self::DELETED_SUB_ORDER, 'attendance_day.deleted', $dayId, [
                'userId' => $identity['userId'],
                'workDate' => $identity['workDate'],
                'reason' => self::DELETED_REASON,
                'deletedByUserId' => $release['operatorUserId'],
                'punchLogAction' => self::PUNCH_LOG_ACTION,
            ], $release['row']->id);
            $deletedAdded = true;
        }

        return new AttendanceRebuild(
            events: $events,
            consumedIds: array_map('intval', array_keys($consumed)),
            createdAdded: $createdAdded,
            deletedAdded: $deletedAdded,
            workTypeRewritten: $rewritten,
            nullItemsFilled: $filled,
            statusRewrittenTargets: $statusRewritten,
        );
    }

    /**
     * 休暇の解除で空になった勤怠日なら、解除の操作(最後の差戻し・取消・却下)を返す。後続のイベントがあるときはnull。
     *
     * @param  list<StoredEventRow>  $rows
     * @param  list<array{userId: string, finalState: string, leaveType?: ?string, lastRelease: ?array{row: StoredEventRow, operatorUserId: string}}>  $leaves
     * @return array{row: StoredEventRow, operatorUserId: string}|null
     */
    private function releaseForEmptyDay(array $rows, ?AttendanceDayCurrent $current, array $leaves, ?string $createdSource, bool $hasDeleted, bool $hasAdjusted): ?array
    {
        if ($current === null || $hasDeleted || $hasAdjusted || $leaves === []) {
            return null;
        }
        if (($createdSource ?? $current->source) !== 'leave') {
            return null;
        }
        // 他に有効な休暇が残る日は空ではない(解除されていない休暇がある)。
        $release = null;
        foreach ($leaves as $leave) {
            if (! in_array($leave['finalState'], ['returned', 'cancelled'], true)) {
                return null;
            }
            $candidate = $leave['lastRelease'] ?? null;
            if ($candidate !== null && ($release === null || $candidate['row']->id > $release['row']->id)) {
                $release = $candidate;
            }
        }
        if ($release === null) {
            return null;
        }

        $blank = fn (?string $v): bool => $v === null || $v === '';
        if ($current->hasActual() || $current->hasPunch || $current->breaks !== [] || $current->leaveSegments !== []
            || ! $blank($current->workLocationType) || ! $blank($current->note)
            || ! ($blank($current->workType) || self::isLeaveWorkType($current->workType))) {
            return null;
        }

        // 後続のイベントが無いときだけ(解除より後に勤怠日のイベントがあれば、その日は解除後も使われている)。
        foreach ($rows as $row) {
            if ($row->id > $release['row']->id) {
                return null;
            }
        }

        return $release;
    }

    /**
     * 既定値nullの userId・workDate を補う。
     *
     * @param  array<string, mixed>  $payload
     * @param  array{userId: ?string, workDate: ?string}  $identity
     * @return array<string, mixed>
     */
    private function fillIdentity(array $payload, array $identity, string $key, StoredEventRow $row, int &$filled): array
    {
        foreach (['userId', 'workDate'] as $field) {
            if (($payload[$field] ?? null) !== null) {
                continue;
            }
            if ($identity[$field] === null) {
                throw new UnsupportedEventSequence($key, "{$row->eventClass}(id={$row->id})の {$field} を補う元が無い");
            }
            $payload[$field] = $identity[$field];
            $filled++;
        }

        return $payload;
    }

    private static function date(string $value): string
    {
        return substr($value, 0, 10);
    }

    /** stored の壁時計時刻('Y-m-d H:i:s')に UTCオフセット(分)を付けたISO8601文字列。 */
    private static function iso(?string $stored, int $offsetMinutes): ?string
    {
        if ($stored === null) {
            return null;
        }
        $abs = abs($offsetMinutes);

        return str_replace(' ', 'T', substr($stored, 0, 19))
            .sprintf('%s%02d:%02d', $offsetMinutes >= 0 ? '+' : '-', intdiv($abs, 60), $abs % 60);
    }
}
