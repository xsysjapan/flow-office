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
use App\Domain\PaidLeaveSchedule\Support\ScheduleEntryStatus;
use Ramsey\Uuid\Uuid;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

/**
 * paid_leave_schedule集約(AggregateId = userIdから決定的に導出した別UUID。
 * `PaidLeaveAccountAggregate`の生UUID=userIdとはuuid空間を分離する。spec.md論点14)。
 * その社員の将来Scheduleエントリ一式
 * (複数の`scheduledOn`)を内部で保持し、「過去確定Schedule不変・未来のみ再計算」
 * (依頼書§27)という不変条件を保証する。
 * docs/changesets/20260906-paid-leave-schedule-assessment/spec.md「仕様確定事項」参照。
 *
 * 状態機械: Scheduled → AssessmentPending → (Eligible|NotEligible|NeedsReview) →
 * (Granted|Cancelled)。
 *
 * Assessmentは版履歴を持たず「現在値」のみを状態として保持する(spec.md論点2)。
 * 再判定・Overrideの都度発行されるイベント自体が`stored_events`上の履歴となる。
 *
 * @phpstan-type ManualOverrideState array{reason: string, byUserId: string, at: string}
 * @phpstan-type AssessmentState array{periodStart: string, periodEnd: string, denominatorDays: int, attendanceDays: int, excludedDays: int, attendanceRate: ?float, policyVersion: string, automaticResult: string, finalResult: string, overrideReason: ?string}
 * @phpstan-type EntryState array{scheduledOn: string, category: string, candidateGrantDays: float, status: string, manualOverride: ?ManualOverrideState, assessment: ?AssessmentState, grantId: ?string}
 */
class PaidLeaveScheduleAggregate extends AggregateRoot
{
    /**
     * この集約専用の固定namespace UUID。`userId`からuuid5で決定的に導出したUUIDを
     * `AggregateId`(=`stored_events.aggregate_uuid`)として使う(spec.md論点14)。
     * `PaidLeaveAccountAggregate`(AggregateId=生のuserId)とはuuid空間が完全に分離される。
     * 値そのものに意味は無いが、デプロイをまたいで不変でなければならない。
     */
    private const AGGREGATE_UUID_NAMESPACE = '2b3f6a2e-4c9b-4b8a-9d0a-6a2e9c2b6a71';

    /** @var array<string, EntryState> */
    private array $entries = [];

    /**
     * 実際の対象社員のuserId。`$this->uuid`(spatieのAggregateRootが持つプロパティ)は
     * `aggregateUuidForUser()`で導出したこの集約専用のUUIDであり、実userIdではないため、
     * replay可能な内部状態として別途保持する。最初のイベント適用時にのみ設定される。
     */
    private ?string $userId = null;

    /**
     * `userId`から決定的に導出したAggregateId(`stored_events.aggregate_uuid`用)。
     * 同じuserIdは常に同じUUIDに解決されるため、`retrieve()`は毎回同じイベントストリームを
     * 参照できる。`PaidLeaveAccountAggregate`の生UUID(=userId)とは別のuuid空間になる。
     */
    public static function aggregateUuidForUser(string $userId): string
    {
        return Uuid::uuid5(self::AGGREGATE_UUID_NAMESPACE, $userId)->toString();
    }

    public function createEntry(
        string $userId,
        string $entryId,
        string $scheduledOn,
        string $category,
        float $candidateGrantDays,
    ): self {
        if (isset($this->entries[$entryId])) {
            throw new DomainRuleException("Scheduleエントリ [{$entryId}] は既に存在します。");
        }

        $this->recordThat(new PaidLeaveScheduleEntryCreated(
            userId: $userId,
            entryId: $entryId,
            scheduledOn: $scheduledOn,
            category: $category,
            candidateGrantDays: $candidateGrantDays,
        ));

        return $this;
    }

    /**
     * 条件変更(hire_date/usage_start_date/work_style_id/独自ルール変更等)による再計算。
     * `manualOverride`済みのエントリは、新算出結果が既存内容と食い違う場合のみ
     * `NeedsReview`へ強制遷移させ、個別修正内容自体は保持する(spec.md論点7)。
     * 食い違わなければ何もしない(冪等)。
     */
    public function supersedeEntry(
        string $entryId,
        string $reason,
        string $newCategory,
        float $newCandidateGrantDays,
    ): self {
        $entry = $this->requireNonTerminalEntry($entryId);

        $wasManuallyOverridden = $entry['manualOverride'] !== null;

        if ($wasManuallyOverridden) {
            $conflict = $newCategory !== $entry['category']
                || abs($newCandidateGrantDays - $entry['candidateGrantDays']) > 0.0001;

            if (! $conflict) {
                // 個別修正内容と新しい算出結果が一致しているため、置き換える必要が無い。
                return $this;
            }

            $this->recordThat(new PaidLeaveScheduleEntrySuperseded(
                userId: $this->userId,
                entryId: $entryId,
                reason: $reason,
                previousCategory: $entry['category'],
                previousCandidateGrantDays: $entry['candidateGrantDays'],
                wasManuallyOverridden: true,
                newCategory: $newCategory,
                newCandidateGrantDays: $newCandidateGrantDays,
                pushedToNeedsReview: true,
            ));

            return $this;
        }

        $this->recordThat(new PaidLeaveScheduleEntrySuperseded(
            userId: $this->userId,
            entryId: $entryId,
            reason: $reason,
            previousCategory: $entry['category'],
            previousCandidateGrantDays: $entry['candidateGrantDays'],
            wasManuallyOverridden: false,
            newCategory: $newCategory,
            newCandidateGrantDays: $newCandidateGrantDays,
            pushedToNeedsReview: false,
        ));

        return $this;
    }

    /**
     * `AttendanceRateAssessor`実行結果を記録する。既に`manualOverride`が存在する場合、
     * 導出ステータスは既存の`finalResult`(Override結果)を維持し、新しい`automaticResult`に
     * 引きずられて上書きしない(spec.md論点7「競合したら要確認」と同じ「個別修正の保護」の
     * 考え方をAssessment再判定にも適用する)。
     */
    public function recordAssessment(
        string $entryId,
        string $periodStart,
        string $periodEnd,
        int $denominatorDays,
        int $attendanceDays,
        int $excludedDays,
        ?float $attendanceRate,
        string $policyVersion,
        string $automaticResult,
    ): self {
        $this->requireNonTerminalEntry($entryId);

        $this->recordThat(new PaidLeaveScheduleAssessmentRecorded(
            userId: $this->userId,
            entryId: $entryId,
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
     * 管理者による自動判定の上書き(依頼書§40)。
     */
    public function overrideAssessment(
        string $entryId,
        string $finalResult,
        string $reason,
        string $byUserId,
        string $at,
    ): self {
        $entry = $this->requireNonTerminalEntry($entryId);

        if ($entry['assessment'] === null) {
            throw new DomainRuleException("Scheduleエントリ [{$entryId}] はまだAssessmentが実行されていません。");
        }

        $this->recordThat(new PaidLeaveScheduleAssessmentOverridden(
            userId: $this->userId,
            entryId: $entryId,
            finalResult: $finalResult,
            reason: $reason,
            byUserId: $byUserId,
            at: $at,
        ));

        return $this;
    }

    /**
     * 管理者によるScheduleエントリの手動修正(区分・候補付与日数)。以後
     * `supersedeEntry`から保護される。
     */
    public function manuallyEditEntry(
        string $entryId,
        ?string $category,
        ?float $candidateGrantDays,
        string $reason,
        string $byUserId,
        string $at,
    ): self {
        $this->requireNonTerminalEntry($entryId);

        $this->recordThat(new PaidLeaveScheduleEntryManuallyEdited(
            userId: $this->userId,
            entryId: $entryId,
            category: $category,
            candidateGrantDays: $candidateGrantDays,
            reason: $reason,
            byUserId: $byUserId,
            at: $at,
        ));

        return $this;
    }

    /**
     * `Eligible`エントリを`Granted`へ遷移させる。`App\Domain\PaidLeaveAccount\Commands\GrantPaidLeave`
     * の発行自体はHandler側の責務(このAggregateは自身の不変条件のみを管理する)。
     */
    public function applyGrant(string $entryId, string $grantId, string $operatorUserId): self
    {
        $entry = $this->entries[$entryId] ?? null;

        if ($entry === null) {
            throw new DomainRuleException("Scheduleエントリ [{$entryId}] は存在しません。");
        }

        if ($entry['status'] !== ScheduleEntryStatus::ELIGIBLE) {
            throw new DomainRuleException("Scheduleエントリ [{$entryId}] はEligibleでないため付与できません(現在: {$entry['status']})。");
        }

        $this->recordThat(new PaidLeaveScheduleEntryGranted(
            userId: $this->userId,
            entryId: $entryId,
            grantId: $grantId,
            operatorUserId: $operatorUserId,
        ));

        return $this;
    }

    public function cancelEntry(string $entryId, ?string $reason, ?string $byUserId): self
    {
        $this->requireNonTerminalEntry($entryId);

        $this->recordThat(new PaidLeaveScheduleEntryCancelled(
            userId: $this->userId,
            entryId: $entryId,
            reason: $reason,
            byUserId: $byUserId,
        ));

        return $this;
    }

    /**
     * Handler側が「その日付のエントリが既に存在するか」をべき等判定するための読み取り専用
     * アクセサ(Projectionが無いPhase Aでは、Handlerがこのメソッド経由でAggregate自身の
     * 状態を参照する)。
     */
    public function entryIdForDate(string $scheduledOn): ?string
    {
        foreach ($this->entries as $entryId => $entry) {
            if ($entry['scheduledOn'] === $scheduledOn) {
                return $entryId;
            }
        }

        return null;
    }

    /**
     * @return null|EntryState
     */
    public function entry(string $entryId): ?array
    {
        return $this->entries[$entryId] ?? null;
    }

    /**
     * Projection未実装(Phase A)のHandlerが、自身の全エントリを列挙するためのアクセサ。
     * Phase B以降、Projectionから列挙できるようになれば呼び出し元をそちらへ寄せてよい。
     *
     * @return array<int, string>
     */
    public function allEntryIds(): array
    {
        return array_keys($this->entries);
    }

    /**
     * @return EntryState
     */
    private function requireNonTerminalEntry(string $entryId): array
    {
        $entry = $this->entries[$entryId] ?? null;

        if ($entry === null) {
            throw new DomainRuleException("Scheduleエントリ [{$entryId}] は存在しません。");
        }

        if (ScheduleEntryStatus::isTerminal($entry['status'])) {
            throw new DomainRuleException("Scheduleエントリ [{$entryId}] は確定済み(現在: {$entry['status']})のため変更できません。");
        }

        return $entry;
    }

    /**
     * 判定文字列(Eligible|NotEligible|NeedsReview)をそのままステータスとして採用する
     * (`ScheduleEntryStatus`の定数値と一致するよう設計している)。
     */
    private function statusFromResult(string $result): string
    {
        return match ($result) {
            ScheduleEntryStatus::ELIGIBLE, ScheduleEntryStatus::NOT_ELIGIBLE, ScheduleEntryStatus::NEEDS_REVIEW => $result,
            default => ScheduleEntryStatus::NEEDS_REVIEW,
        };
    }

    protected function applyPaidLeaveScheduleEntryCreated(PaidLeaveScheduleEntryCreated $event): void
    {
        $this->userId = $event->userId;

        $this->entries[$event->entryId] = [
            'scheduledOn' => $event->scheduledOn,
            'category' => $event->category,
            'candidateGrantDays' => $event->candidateGrantDays,
            'status' => ScheduleEntryStatus::SCHEDULED,
            'manualOverride' => null,
            'assessment' => null,
            'grantId' => null,
        ];
    }

    protected function applyPaidLeaveScheduleEntrySuperseded(PaidLeaveScheduleEntrySuperseded $event): void
    {
        $entry = $this->entries[$event->entryId];

        if ($event->pushedToNeedsReview) {
            // 個別修正内容(category/candidateGrantDays/manualOverride)は保持したまま、
            // ステータスのみNeedsReviewへ強制遷移させる。
            $entry['status'] = ScheduleEntryStatus::NEEDS_REVIEW;
        } else {
            $entry['category'] = $event->newCategory;
            $entry['candidateGrantDays'] = $event->newCandidateGrantDays;
            // 区分・候補日数が変わったため、古いAssessmentは前提が変わっており、
            // 再判定が必要な状態(Scheduled)へ戻す。
            $entry['status'] = ScheduleEntryStatus::SCHEDULED;
            $entry['assessment'] = null;
        }

        $this->entries[$event->entryId] = $entry;
    }

    protected function applyPaidLeaveScheduleAssessmentRecorded(PaidLeaveScheduleAssessmentRecorded $event): void
    {
        $entry = $this->entries[$event->entryId];
        $hasOverride = $entry['manualOverride'] !== null;

        $finalResult = $hasOverride
            ? ($entry['assessment']['finalResult'] ?? $event->automaticResult)
            : $event->automaticResult;

        $overrideReason = $hasOverride ? ($entry['assessment']['overrideReason'] ?? null) : null;

        $entry['assessment'] = [
            'periodStart' => $event->periodStart,
            'periodEnd' => $event->periodEnd,
            'denominatorDays' => $event->denominatorDays,
            'attendanceDays' => $event->attendanceDays,
            'excludedDays' => $event->excludedDays,
            'attendanceRate' => $event->attendanceRate,
            'policyVersion' => $event->policyVersion,
            'automaticResult' => $event->automaticResult,
            'finalResult' => $finalResult,
            'overrideReason' => $overrideReason,
        ];

        // 既にOverride済みのエントリは、新しい自動判定結果ではなく既存のfinalResultに
        // 基づいたステータスを維持する(自動再判定によるOverrideの黙殺を防ぐ)。
        $entry['status'] = $hasOverride
            ? $this->statusFromResult($finalResult)
            : $this->statusFromResult($event->automaticResult);

        $this->entries[$event->entryId] = $entry;
    }

    protected function applyPaidLeaveScheduleAssessmentOverridden(PaidLeaveScheduleAssessmentOverridden $event): void
    {
        $entry = $this->entries[$event->entryId];

        $entry['manualOverride'] = [
            'reason' => $event->reason,
            'byUserId' => $event->byUserId,
            'at' => $event->at,
        ];

        $entry['assessment']['finalResult'] = $event->finalResult;
        $entry['assessment']['overrideReason'] = $event->reason;
        $entry['status'] = $this->statusFromResult($event->finalResult);

        $this->entries[$event->entryId] = $entry;
    }

    protected function applyPaidLeaveScheduleEntryManuallyEdited(PaidLeaveScheduleEntryManuallyEdited $event): void
    {
        $entry = $this->entries[$event->entryId];

        if ($event->category !== null) {
            $entry['category'] = $event->category;
        }

        if ($event->candidateGrantDays !== null) {
            $entry['candidateGrantDays'] = $event->candidateGrantDays;
        }

        $entry['manualOverride'] = [
            'reason' => $event->reason,
            'byUserId' => $event->byUserId,
            'at' => $event->at,
        ];

        $this->entries[$event->entryId] = $entry;
    }

    protected function applyPaidLeaveScheduleEntryGranted(PaidLeaveScheduleEntryGranted $event): void
    {
        $this->entries[$event->entryId]['status'] = ScheduleEntryStatus::GRANTED;
        $this->entries[$event->entryId]['grantId'] = $event->grantId;
    }

    protected function applyPaidLeaveScheduleEntryCancelled(PaidLeaveScheduleEntryCancelled $event): void
    {
        $this->entries[$event->entryId]['status'] = ScheduleEntryStatus::CANCELLED;
    }
}
