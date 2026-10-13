<?php

namespace App\Domain\EventSourcing\Rebuild;

use App\Models\PaidLeaveScheduleEntry;

/**
 * 旧版の付与予定イベント(paid_leave_schedule.*)を、現行の形のイベント列に置き換える(論点19 決定3)。
 *
 * - 旧版: event_class が paid_leave_schedule.* で、payload に entryId があり scheduleEntryId が無いもの。全て削除する。
 * - 旧版に現れた entryId ごとに、ReadModel(paid_leave_schedule_entries・paid_leave_schedule_assessments)の現在の行から、
 *   現行のProjector(PaidLeaveScheduleProjector)で再生すると現在の行が再現されるイベント列を導出する。
 * - 発生日時: 旧版の同種のイベントがあればその created_at。無ければ entry_manually_edited は行の manual_override_at、
 *   entry_cancelled は行の updated_at、それ以外(または null)は entry_created と同じ(行の created_at)。
 * - 集約は利用者(行の user_id)。置く位置は、その利用者の旧版のイベントの最初の位置。
 * - 行が無い・必須の値が決められない entryId は UnsupportedEventSequence 相当の問題として issues に載せる。
 */
final class PaidLeaveScheduleRebuilder
{
    public const CLASS_PREFIX = 'paid_leave_schedule.';

    public const CANCELLED_REASON = '既存データの引き継ぎ';

    public static function isLegacyRow(StoredEventRow $row): bool
    {
        return str_starts_with($row->eventClass, self::CLASS_PREFIX)
            && $row->get('entryId') !== null
            && $row->get('scheduleEntryId') === null;
    }

    /**
     * @param  list<StoredEventRow>  $legacyRows  旧版の行(isLegacyRow が真のもの)
     * @param  array<string, PaidLeaveScheduleCurrent>  $currents  行id(entryId) => 現在値
     */
    public function rebuild(array $legacyRows, array $currents): PaidLeaveScheduleRebuild
    {
        usort($legacyRows, fn (StoredEventRow $a, StoredEventRow $b) => $a->id <=> $b->id);

        $consumed = [];
        $replaced = [];
        /** @var array<string, list<StoredEventRow>> $byEntry */
        $byEntry = [];
        foreach ($legacyRows as $row) {
            $consumed[] = $row->id;
            $replaced[$row->eventClass] = ($replaced[$row->eventClass] ?? 0) + 1;
            $byEntry[(string) $row->get('entryId')][] = $row;
        }

        $events = [];
        $derivedByClass = [];
        $issues = [];
        /** @var array<string, array{anchor: StoredEventRow, subOrder: int}> $users */
        $users = [];

        foreach ($byEntry as $entryId => $rowsOfEntry) {
            $current = $currents[$entryId] ?? null;
            if ($current === null) {
                $issues[] = ['key' => $entryId, 'reason' => 'ReadModel(paid_leave_schedule_entries)に行が無く、現行のイベントを導出できない'];

                continue;
            }
            try {
                $derived = $this->derive($entryId, $current, $rowsOfEntry);
            } catch (UnsupportedEventSequence $e) {
                $issues[] = ['key' => $e->key, 'reason' => $e->getMessage()];

                continue;
            }

            $userId = (string) $current->userId;
            $users[$userId] ??= ['anchor' => $rowsOfEntry[0], 'subOrder' => 0];
            $anchor = $users[$userId]['anchor'];
            foreach ($derived as [$class, $payload, $createdAt]) {
                $events[] = new RebuiltEvent(
                    eventClass: $class,
                    aggregateUuid: $userId,
                    payload: $payload,
                    meta: $anchor->meta,
                    createdAt: $createdAt ?? $anchor->createdAt,
                    anchorId: $anchor->id,
                    subOrder: $users[$userId]['subOrder']++,
                    rebuiltFrom: null,
                    sourceVersion: null,
                );
                $derivedByClass[$class] = ($derivedByClass[$class] ?? 0) + 1;
            }
        }

        return new PaidLeaveScheduleRebuild(
            events: $events,
            consumedIds: $consumed,
            replacedByClass: $replaced,
            derivedByClass: $derivedByClass,
            issues: $issues,
        );
    }

    /**
     * 1行ぶんの現行のイベント列。
     *
     * @param  list<StoredEventRow>  $rowsOfEntry  その entryId の旧版の行(id順)
     * @return list<array{0: string, 1: array<string, mixed>, 2: ?string}> [event_class, payload, created_at]
     */
    private function derive(string $entryId, PaidLeaveScheduleCurrent $current, array $rowsOfEntry): array
    {
        $key = "paid_leave_schedule_entry {$entryId}";
        $require = function (mixed $value, string $what) use ($key): mixed {
            if ($value === null || $value === '') {
                throw new UnsupportedEventSequence($key, "{$what} が null で、現行のイベントの必須引数を決められない");
            }

            return $value;
        };

        $userId = (string) $require($current->userId, 'user_id');
        $createdAt = $current->createdAt;
        $oldCreatedAt = function (string $suffix, int $index = 0, ?string $fallback = null) use ($rowsOfEntry, $createdAt): ?string {
            $matching = array_values(array_filter($rowsOfEntry, fn (StoredEventRow $r) => $r->eventClass === self::CLASS_PREFIX.$suffix));

            return ($matching[$index] ?? null)?->createdAt ?? $fallback ?? $createdAt;
        };

        $out = [];
        $out[] = ['paid_leave_schedule.entry_created', [
            'scheduleEntryId' => $entryId,
            'scheduledOn' => $this->date((string) $require($current->scheduledOn, 'scheduled_on')),
            'category' => (string) $require($current->category, 'category'),
            'candidateGrantDays' => (float) $require($current->candidateGrantDays, 'candidate_grant_days'),
            'isDeterminate' => $current->status !== PaidLeaveScheduleEntry::STATUS_NEEDS_REVIEW,
        ], $createdAt];

        foreach ($current->assessments as $i => $assessment) {
            $akey = "assessment {$assessment['id']}";
            $field = function (string $name, string $column) use ($assessment, $key, $akey): mixed {
                if ($assessment[$name] === null || $assessment[$name] === '') {
                    throw new UnsupportedEventSequence($key, "{$akey} の {$column} が null で、現行のイベントの必須引数を決められない");
                }

                return $assessment[$name];
            };
            $automatic = (string) $field('automaticResult', 'automatic_result');
            $out[] = ['paid_leave_schedule.assessment_recorded', [
                'scheduleEntryId' => $entryId,
                'assessmentId' => $assessment['id'],
                'periodStart' => $this->date((string) $field('periodStart', 'period_start')),
                'periodEnd' => $this->date((string) $field('periodEnd', 'period_end')),
                'denominatorDays' => (int) $field('denominatorDays', 'denominator_days'),
                'attendanceDays' => (int) $field('attendanceDays', 'attendance_days'),
                'excludedDays' => (int) ($assessment['excludedDays'] ?? 0),
                'attendanceRate' => $assessment['attendanceRate'],
                'policyVersion' => (string) $field('policyVersion', 'policy_version'),
                'automaticResult' => $automatic,
            ], $oldCreatedAt('assessment_recorded', $i)];

            $final = $assessment['finalResult'] ?? $automatic;
            if ($final !== $automatic) {
                $out[] = ['paid_leave_schedule.assessment_overridden', [
                    'scheduleEntryId' => $entryId,
                    'assessmentId' => $assessment['id'],
                    'finalResult' => (string) $final,
                    'reason' => (string) $field('overrideReason', 'override_reason'),
                    'operatorUserId' => (string) $field('overriddenByUserId', 'overridden_by_user_id'),
                ], $oldCreatedAt('assessment_overridden', $i)];
            }
        }

        if ($current->isManuallyOverridden) {
            $out[] = ['paid_leave_schedule.entry_manually_edited', [
                'scheduleEntryId' => $entryId,
                'changes' => [
                    'category' => (string) $require($current->category, 'category'),
                    'candidateGrantDays' => (float) $require($current->candidateGrantDays, 'candidate_grant_days'),
                ],
                'reason' => (string) $require($current->manualOverrideReason, 'manual_override_reason'),
                'operatorUserId' => (string) $require($current->manualOverrideByUserId, 'manual_override_by_user_id'),
            ], $oldCreatedAt('entry_manually_edited', 0, $current->manualOverrideAt)];
        }

        if ($current->grantId !== null && $current->grantId !== '') {
            $operator = $userId;
            foreach ($rowsOfEntry as $row) {
                if ($row->eventClass === self::CLASS_PREFIX.'entry_granted' && $row->get('operatorUserId') !== null) {
                    $operator = (string) $row->get('operatorUserId');
                }
            }
            $out[] = ['paid_leave_schedule.entry_granted', [
                'scheduleEntryId' => $entryId,
                'grantId' => $current->grantId,
                'operatorUserId' => $operator,
            ], $oldCreatedAt('entry_granted')];
        }

        if ($current->status === PaidLeaveScheduleEntry::STATUS_CANCELLED) {
            $reason = $current->cancelledReason;
            $out[] = ['paid_leave_schedule.entry_cancelled', [
                'scheduleEntryId' => $entryId,
                'reason' => $reason !== null && $reason !== '' ? $reason : self::CANCELLED_REASON,
            ], $oldCreatedAt('entry_cancelled', 0, $current->updatedAt)];
        }

        return $out;
    }

    private function date(string $value): string
    {
        return substr($value, 0, 10);
    }
}
