<?php

namespace App\Domain\PaidLeaveSchedule\Aggregates;

use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\PaidLeaveSchedule\Events\PaidLeaveScheduleAssessmentOverridden;
use App\Domain\PaidLeaveSchedule\Events\PaidLeaveScheduleAssessmentRecorded;
use App\Domain\PaidLeaveSchedule\Events\PaidLeaveScheduleEntryCancelled;
use App\Domain\PaidLeaveSchedule\Events\PaidLeaveScheduleEntryCreated;
use App\Domain\PaidLeaveSchedule\Events\PaidLeaveScheduleEntryGranted;
use App\Domain\PaidLeaveSchedule\Events\PaidLeaveScheduleEntryManuallyEdited;
use App\Domain\PaidLeaveSchedule\Events\PaidLeaveScheduleEntrySuperseded;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * paid_leave_schedule集約(AggregateId = userId)。社員一人の将来付与予定Schedule一式を
 * 内部で保持し、「過去確定Schedule不変」「個別修正の保護」という不変条件を、replayされた
 * 自身の内部状態のみで保証する(Projection/Eloquentへは一切アクセスしない)。
 * docs/changesets/20260906-paid-leave-schedule-assessment/spec.md「仕様確定事項」参照。
 *
 * 状態遷移: Scheduled → AssessmentPending → (Eligible|NotEligible|NeedsReview) →
 * (Granted|Cancelled)。
 *
 * 出勤率Assessmentの分母/分子算出そのものは`Support\AttendanceRateAssessor`が、
 * 通常/比例/シフト区分の算出は`Support\GrantCategoryClassifier`が、いずれも
 * ステートレスにCommandHandler側で行い、この集約はその結果の記録・状態遷移のみを担う。
 *
 * @phpstan-type AssessmentState array{periodStart: string, periodEnd: string, denominatorDays: int, attendanceDays: int, excludedDays: int, attendanceRate: ?float, policyVersion: string, automaticResult: string, finalResult: string, overrideReason: ?string}
 * @phpstan-type EntryState array{scheduledOn: string, category: string, candidateGrantDays: float, status: string, manualOverride: ?array{reason: string, byUserId: string, at: ?string}, assessments: array<string, AssessmentState>, latestAssessmentId: ?string}
 */
class PaidLeaveScheduleAggregate extends AggregateRoot
{
    public const STATUS_SCHEDULED = 'Scheduled';

    public const STATUS_ASSESSMENT_PENDING = 'AssessmentPending';

    public const STATUS_ELIGIBLE = 'Eligible';

    public const STATUS_NOT_ELIGIBLE = 'NotEligible';

    public const STATUS_NEEDS_REVIEW = 'NeedsReview';

    public const STATUS_GRANTED = 'Granted';

    public const STATUS_CANCELLED = 'Cancelled';

    private const FINALIZED_STATUSES = [self::STATUS_GRANTED, self::STATUS_CANCELLED];

    /** @var array<string, EntryState> */
    private array $entries = [];

    /**
     * 対象社員について、1年先までのScheduleエントリ存在を保証する(べき等)。
     * `scheduledOn`に一致する未取消エントリが既に存在する場合はスキップする
     * (新規社員登録時・日次ロール生成の両方から同じロジックを使う)。
     *
     * @param  array<int, array{entryId: string, scheduledOn: string, category: string, candidateGrantDays: float}>  $candidates
     */
    public function ensureFutureScheduleGenerated(array $candidates): self
    {
        foreach ($candidates as $candidate) {
            if ($this->findEntryIdByScheduledOn($candidate['scheduledOn']) !== null) {
                continue;
            }

            $this->createEntry($candidate);
        }

        return $this;
    }

    /**
     * hire_date/usage_start_date/work_style_id割当/独自ルール変更等の条件変更時に、対象社員の
     * 未来Scheduleを再計算する。`Granted`/`Cancelled`の確定済みエントリは常に対象外とする。
     * 個別修正済み(`manuallyEditScheduleEntry`実行済み)エントリは、既定では対象外とするが
     * (依頼書§28)、`$overrideManualEdits`が`true`の場合はこの保護を無視する
     * (法定付与ポリシー・付与ルール自体が変更された場合。未確定の予定は個別修正の有無に
     * 関わらず新しい内容に追従させる、docs/changesets/20260916-paid-leave-policy-change-reapply/spec.md)。
     *
     * @param  array<int, array{entryId: string, scheduledOn: string, category: string, candidateGrantDays: float}>  $candidates
     */
    public function recalculateFutureSchedule(array $candidates, string $reason, bool $overrideManualEdits = false): self
    {
        $desiredByScheduledOn = [];
        foreach ($candidates as $candidate) {
            $desiredByScheduledOn[$candidate['scheduledOn']] = $candidate;
        }

        // 既存の再計算対象エントリ(確定済みを除く。個別修正済みは$overrideManualEdits次第)を走査する。
        foreach ($this->entries as $entryId => $entry) {
            if (in_array($entry['status'], self::FINALIZED_STATUSES, true)) {
                continue;
            }

            if ($entry['manualOverride'] !== null && ! $overrideManualEdits) {
                // 個別修正済みエントリは黙って上書きしない(依頼書§28)。内容の食い違い検知
                // (NeedsReviewへの強制遷移)はPhase C以降、法定Policyが揃ってから
                // 実際の条件比較として実装する。Phase Aでは「保護対象から除外する」
                // (=一切触らない)ことのみを保証する。
                // 同じscheduledOnの候補をここで消費しておかないと、下部の「残った候補を
                // 新規作成する」ループでこのエントリと重複する新規エントリが作られてしまう。
                unset($desiredByScheduledOn[$entry['scheduledOn']]);

                continue;
            }

            $desired = $desiredByScheduledOn[$entry['scheduledOn']] ?? null;

            if ($desired === null) {
                // 最新条件ではこの日付にScheduleが不要になった。
                $this->recordThat(new PaidLeaveScheduleEntryCancelled(
                    scheduleEntryId: $entryId,
                    reason: $reason,
                ));

                continue;
            }

            unset($desiredByScheduledOn[$entry['scheduledOn']]);

            if ($desired['category'] === $entry['category']
                && abs($desired['candidateGrantDays'] - $entry['candidateGrantDays']) < 0.0001) {
                // 内容が変わらないため何もしない(冪等)。
                continue;
            }

            $this->recordThat(new PaidLeaveScheduleEntrySuperseded(
                scheduleEntryId: $entryId,
                reason: $reason,
                previousScheduledOn: $entry['scheduledOn'],
                previousCategory: $entry['category'],
                previousCandidateGrantDays: $entry['candidateGrantDays'],
            ));

            $this->createEntry($desired);
        }

        // 残った(既存に一致するものが無かった)候補は新規作成する。
        foreach ($desiredByScheduledOn as $candidate) {
            $this->createEntry($candidate);
        }

        return $this;
    }

    /**
     * 出勤率Assessment結果を記録し、判定結果に応じて状態を遷移させる
     * (Eligible|NotEligible|NeedsReview)。分母/分子等の算出は
     * `Support\AttendanceRateAssessor`が担い、この集約は結果を記録するのみ。
     */
    public function runAttendanceRateAssessment(
        string $scheduleEntryId,
        string $assessmentId,
        string $periodStart,
        string $periodEnd,
        int $denominatorDays,
        int $attendanceDays,
        int $excludedDays,
        ?float $attendanceRate,
        string $policyVersion,
        string $automaticResult,
    ): self {
        $entry = $this->requireEntry($scheduleEntryId);

        if (in_array($entry['status'], self::FINALIZED_STATUSES, true)) {
            throw new DomainRuleException("Scheduleエントリ [{$scheduleEntryId}] は確定済み・取消済みのため判定できません。");
        }

        $this->recordThat(new PaidLeaveScheduleAssessmentRecorded(
            scheduleEntryId: $scheduleEntryId,
            assessmentId: $assessmentId,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            denominatorDays: $denominatorDays,
            attendanceDays: $attendanceDays,
            excludedDays: $excludedDays,
            attendanceRate: $attendanceRate,
            policyVersion: $policyVersion,
            automaticResult: $automaticResult,
        ));

        return $this;
    }

    /**
     * 管理者による判定結果の上書き(依頼書§40)。理由必須。
     */
    public function overrideScheduleAssessment(
        string $scheduleEntryId,
        string $finalResult,
        string $reason,
        string $operatorUserId,
    ): self {
        $entry = $this->requireEntry($scheduleEntryId);

        if (in_array($entry['status'], self::FINALIZED_STATUSES, true)) {
            throw new DomainRuleException("Scheduleエントリ [{$scheduleEntryId}] は確定済み・取消済みのため判定を上書きできません。");
        }

        if ($entry['latestAssessmentId'] === null) {
            throw new DomainRuleException("Scheduleエントリ [{$scheduleEntryId}] はまだAssessmentが実行されていません。");
        }

        $this->recordThat(new PaidLeaveScheduleAssessmentOverridden(
            scheduleEntryId: $scheduleEntryId,
            assessmentId: $entry['latestAssessmentId'],
            finalResult: $finalResult,
            reason: $reason,
            operatorUserId: $operatorUserId,
        ));

        return $this;
    }

    /**
     * Scheduleエントリの個別修正。修正後は`recalculateFutureSchedule`の対象から除外される。
     *
     * @param  array{scheduledOn?: string, category?: string, candidateGrantDays?: float}  $changes
     */
    public function manuallyEditScheduleEntry(
        string $scheduleEntryId,
        array $changes,
        string $reason,
        string $operatorUserId,
    ): self {
        $entry = $this->requireEntry($scheduleEntryId);

        if (in_array($entry['status'], self::FINALIZED_STATUSES, true)) {
            throw new DomainRuleException("Scheduleエントリ [{$scheduleEntryId}] は確定済み・取消済みのため編集できません。");
        }

        $this->recordThat(new PaidLeaveScheduleEntryManuallyEdited(
            scheduleEntryId: $scheduleEntryId,
            changes: $changes,
            reason: $reason,
            operatorUserId: $operatorUserId,
        ));

        return $this;
    }

    /**
     * 管理者の一括付与操作で、既にGrantが発行済みの1エントリをGrantedへ遷移させる
     * (実際の`GrantPaidLeave`発行はCommandHandlerがCommandBus経由で行う。この集約は
     * Schedule側の状態遷移のみを担う)。
     */
    public function grantEntry(string $scheduleEntryId, string $grantId, string $operatorUserId): self
    {
        $entry = $this->requireEntry($scheduleEntryId);

        if ($entry['status'] !== self::STATUS_ELIGIBLE) {
            throw new DomainRuleException("Scheduleエントリ [{$scheduleEntryId}] はEligible状態のもののみ付与できます。");
        }

        $this->recordThat(new PaidLeaveScheduleEntryGranted(
            scheduleEntryId: $scheduleEntryId,
            grantId: $grantId,
            operatorUserId: $operatorUserId,
        ));

        return $this;
    }

    public function cancelEntry(string $scheduleEntryId, string $reason): self
    {
        $entry = $this->requireEntry($scheduleEntryId);

        if (in_array($entry['status'], self::FINALIZED_STATUSES, true)) {
            throw new DomainRuleException("Scheduleエントリ [{$scheduleEntryId}] は既に確定済み・取消済みです。");
        }

        $this->recordThat(new PaidLeaveScheduleEntryCancelled(
            scheduleEntryId: $scheduleEntryId,
            reason: $reason,
        ));

        return $this;
    }

    /**
     * @return EntryState
     */
    public function entry(string $scheduleEntryId): array
    {
        return $this->requireEntry($scheduleEntryId);
    }

    /**
     * @return array<string, EntryState>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * @param  array{entryId: string, scheduledOn: string, category: string, candidateGrantDays: float, isDeterminate?: bool}  $candidate
     */
    private function createEntry(array $candidate): void
    {
        $this->recordThat(new PaidLeaveScheduleEntryCreated(
            scheduleEntryId: $candidate['entryId'],
            scheduledOn: $candidate['scheduledOn'],
            category: $candidate['category'],
            candidateGrantDays: $candidate['candidateGrantDays'],
            isDeterminate: $candidate['isDeterminate'] ?? true,
        ));
    }

    private function findEntryIdByScheduledOn(string $scheduledOn): ?string
    {
        foreach ($this->entries as $entryId => $entry) {
            if ($entry['scheduledOn'] === $scheduledOn && $entry['status'] !== self::STATUS_CANCELLED) {
                return $entryId;
            }
        }

        return null;
    }

    /**
     * @return EntryState
     */
    private function requireEntry(string $scheduleEntryId): array
    {
        $entry = $this->entries[$scheduleEntryId] ?? null;

        if ($entry === null) {
            throw new DomainRuleException("Scheduleエントリ [{$scheduleEntryId}] は存在しません。");
        }

        return $entry;
    }

    protected function applyPaidLeaveScheduleEntryCreated(PaidLeaveScheduleEntryCreated $event): void
    {
        $this->entries[$event->scheduleEntryId] = [
            'scheduledOn' => $event->scheduledOn,
            'category' => $event->category,
            'candidateGrantDays' => $event->candidateGrantDays,
            'status' => $event->isDeterminate ? self::STATUS_SCHEDULED : self::STATUS_NEEDS_REVIEW,
            'manualOverride' => null,
            'assessments' => [],
            'latestAssessmentId' => null,
        ];
    }

    protected function applyPaidLeaveScheduleEntrySuperseded(PaidLeaveScheduleEntrySuperseded $event): void
    {
        // 置き換え対象のエントリはCancelled相当として扱い、再計算・新規充当対象から外す。
        $this->entries[$event->scheduleEntryId]['status'] = self::STATUS_CANCELLED;
    }

    protected function applyPaidLeaveScheduleAssessmentRecorded(PaidLeaveScheduleAssessmentRecorded $event): void
    {
        $this->entries[$event->scheduleEntryId]['assessments'][$event->assessmentId] = [
            'periodStart' => $event->periodStart,
            'periodEnd' => $event->periodEnd,
            'denominatorDays' => $event->denominatorDays,
            'attendanceDays' => $event->attendanceDays,
            'excludedDays' => $event->excludedDays,
            'attendanceRate' => $event->attendanceRate,
            'policyVersion' => $event->policyVersion,
            'automaticResult' => $event->automaticResult,
            'finalResult' => $event->automaticResult,
            'overrideReason' => null,
        ];
        $this->entries[$event->scheduleEntryId]['latestAssessmentId'] = $event->assessmentId;
        $this->entries[$event->scheduleEntryId]['status'] = $event->automaticResult;
    }

    protected function applyPaidLeaveScheduleAssessmentOverridden(PaidLeaveScheduleAssessmentOverridden $event): void
    {
        $this->entries[$event->scheduleEntryId]['assessments'][$event->assessmentId]['finalResult'] = $event->finalResult;
        $this->entries[$event->scheduleEntryId]['assessments'][$event->assessmentId]['overrideReason'] = $event->reason;
        $this->entries[$event->scheduleEntryId]['status'] = $event->finalResult;
    }

    protected function applyPaidLeaveScheduleEntryManuallyEdited(PaidLeaveScheduleEntryManuallyEdited $event): void
    {
        $entry = &$this->entries[$event->scheduleEntryId];

        foreach ($event->changes as $key => $value) {
            if (array_key_exists($key, $entry)) {
                $entry[$key] = $value;
            }
        }

        $entry['manualOverride'] = [
            'reason' => $event->reason,
            'byUserId' => $event->operatorUserId,
            'at' => $event->createdAt()?->toIso8601String(),
        ];
    }

    protected function applyPaidLeaveScheduleEntryGranted(PaidLeaveScheduleEntryGranted $event): void
    {
        $this->entries[$event->scheduleEntryId]['status'] = self::STATUS_GRANTED;
    }

    protected function applyPaidLeaveScheduleEntryCancelled(PaidLeaveScheduleEntryCancelled $event): void
    {
        $this->entries[$event->scheduleEntryId]['status'] = self::STATUS_CANCELLED;
    }
}
