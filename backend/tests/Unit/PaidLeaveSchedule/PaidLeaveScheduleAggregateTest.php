<?php

namespace Tests\Unit\PaidLeaveSchedule;

use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\PaidLeaveSchedule\Aggregates\PaidLeaveScheduleAggregate;
use App\Domain\PaidLeaveSchedule\Events\PaidLeaveScheduleAssessmentOverridden;
use App\Domain\PaidLeaveSchedule\Events\PaidLeaveScheduleAssessmentRecorded;
use App\Domain\PaidLeaveSchedule\Events\PaidLeaveScheduleEntryCancelled;
use App\Domain\PaidLeaveSchedule\Events\PaidLeaveScheduleEntryCreated;
use App\Domain\PaidLeaveSchedule\Events\PaidLeaveScheduleEntryGranted;
use App\Domain\PaidLeaveSchedule\Events\PaidLeaveScheduleEntryManuallyEdited;
use App\Domain\PaidLeaveSchedule\Events\PaidLeaveScheduleEntrySuperseded;
use App\Domain\PaidLeaveSchedule\Support\GrantCategory;
use App\Domain\PaidLeaveSchedule\Support\ScheduleEntryStatus;
use Tests\TestCase;

/**
 * PaidLeaveScheduleAggregateの単体テスト。すべてEvent→Aggregate replay→Commandの結果を
 * 検証する形式で行う(`PaidLeaveAccountAggregateTest`と同じ`::fake()`イディオム)。
 * 状態の検証は`when()`のコールバック内(recordThat直後、applyPaidLeave...が適用済み)で
 * 行う(`::fake()`はDBへ永続化しないため、`::retrieve()`で読み直すことはできない)。
 * docs/changesets/20260906-paid-leave-schedule-assessment/spec.md Phase A。
 */
class PaidLeaveScheduleAggregateTest extends TestCase
{
    private const USER = 'user-1';

    private const ENTRY = 'entry-1';

    // ---- エントリ作成 ----

    public function test_entry_is_created_as_scheduled(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->createEntry(self::ENTRY, '2026-10-01', GrantCategory::REGULAR, 11.0);
                $this->assertSame(ScheduleEntryStatus::SCHEDULED, $aggregate->entry(self::ENTRY)['status']);
            })
            ->assertRecorded([
                new PaidLeaveScheduleEntryCreated(self::ENTRY, '2026-10-01', GrantCategory::REGULAR, 11.0),
            ]);
    }

    public function test_duplicate_entry_id_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        PaidLeaveScheduleAggregate::fake(self::USER)
            ->given([new PaidLeaveScheduleEntryCreated(self::ENTRY, '2026-10-01', GrantCategory::REGULAR, 11.0)])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->createEntry(self::ENTRY, '2026-10-01', GrantCategory::REGULAR, 11.0);
            });
    }

    // ---- Supersede: 非上書きエントリは差し替えられる ----

    public function test_supersede_on_non_overridden_entry_replaces_values(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)
            ->given([new PaidLeaveScheduleEntryCreated(self::ENTRY, '2026-10-01', GrantCategory::REGULAR, 11.0)])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->supersedeEntry(self::ENTRY, 'work_style変更', GrantCategory::PROPORTIONAL, 7.0);

                $entry = $aggregate->entry(self::ENTRY);
                $this->assertSame(GrantCategory::PROPORTIONAL, $entry['category']);
                $this->assertSame(7.0, $entry['candidateGrantDays']);
                $this->assertSame(ScheduleEntryStatus::SCHEDULED, $entry['status']);
            })
            ->assertRecorded([
                new PaidLeaveScheduleEntrySuperseded(
                    self::ENTRY, 'work_style変更', GrantCategory::REGULAR, 11.0,
                    false, GrantCategory::PROPORTIONAL, 7.0, false,
                ),
            ]);
    }

    // ---- Supersede: 個別修正済みエントリは食い違えばNeedsReviewへ、内容は保持 ----

    public function test_supersede_on_manually_overridden_entry_pushes_to_needs_review_without_discarding_manual_content(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated(self::ENTRY, '2026-10-01', GrantCategory::REGULAR, 11.0),
                new PaidLeaveScheduleEntryManuallyEdited(self::ENTRY, GrantCategory::SHIFT, 5.0, '本人希望によりシフト区分へ修正', 'hr-1', '2026-09-01T00:00:00+09:00'),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->supersedeEntry(self::ENTRY, 'policy変更', GrantCategory::REGULAR, 11.0);

                $entry = $aggregate->entry(self::ENTRY);
                $this->assertSame(ScheduleEntryStatus::NEEDS_REVIEW, $entry['status']);
                // 個別修正内容自体(category/candidateGrantDays)は保持される。
                $this->assertSame(GrantCategory::SHIFT, $entry['category']);
                $this->assertSame(5.0, $entry['candidateGrantDays']);
                $this->assertNotNull($entry['manualOverride']);
            })
            ->assertRecorded([
                new PaidLeaveScheduleEntrySuperseded(
                    self::ENTRY, 'policy変更', GrantCategory::SHIFT, 5.0,
                    true, GrantCategory::REGULAR, 11.0, true,
                ),
            ]);
    }

    public function test_supersede_on_manually_overridden_entry_is_noop_when_new_result_matches(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated(self::ENTRY, '2026-10-01', GrantCategory::REGULAR, 11.0),
                new PaidLeaveScheduleEntryManuallyEdited(self::ENTRY, GrantCategory::REGULAR, 11.0, '確認済み', 'hr-1', '2026-09-01T00:00:00+09:00'),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->supersedeEntry(self::ENTRY, 'policy変更', GrantCategory::REGULAR, 11.0);
            })
            ->assertNotRecorded(PaidLeaveScheduleEntrySuperseded::class);
    }

    // ---- Assessment記録がステータスを導出する ----

    public function test_recording_assessment_drives_status_to_eligible(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)
            ->given([new PaidLeaveScheduleEntryCreated(self::ENTRY, '2026-10-01', GrantCategory::REGULAR, 11.0)])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->recordAssessment(
                    self::ENTRY, '2025-10-01', '2026-09-30', 200, 190, 0, 95.0, 'v1', ScheduleEntryStatus::ELIGIBLE,
                );

                $entry = $aggregate->entry(self::ENTRY);
                $this->assertSame(ScheduleEntryStatus::ELIGIBLE, $entry['status']);
                $this->assertSame(ScheduleEntryStatus::ELIGIBLE, $entry['assessment']['finalResult']);
            })
            ->assertRecorded([
                new PaidLeaveScheduleAssessmentRecorded(self::ENTRY, '2025-10-01', '2026-09-30', 200, 190, 0, 95.0, 'v1', ScheduleEntryStatus::ELIGIBLE),
            ]);
    }

    public function test_recording_assessment_drives_status_to_not_eligible(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)
            ->given([new PaidLeaveScheduleEntryCreated(self::ENTRY, '2026-10-01', GrantCategory::REGULAR, 11.0)])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->recordAssessment(
                    self::ENTRY, '2025-10-01', '2026-09-30', 200, 100, 0, 50.0, 'v1', ScheduleEntryStatus::NOT_ELIGIBLE,
                );

                $this->assertSame(ScheduleEntryStatus::NOT_ELIGIBLE, $aggregate->entry(self::ENTRY)['status']);
            });
    }

    // ---- 既存Overrideは自動再判定で黙って上書きされない ----

    public function test_existing_override_is_not_silently_clobbered_by_fresh_automatic_assessment(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated(self::ENTRY, '2026-10-01', GrantCategory::REGULAR, 11.0),
                new PaidLeaveScheduleAssessmentRecorded(self::ENTRY, '2025-10-01', '2026-09-30', 200, 100, 0, 50.0, 'v1', ScheduleEntryStatus::NOT_ELIGIBLE),
                new PaidLeaveScheduleAssessmentOverridden(self::ENTRY, ScheduleEntryStatus::ELIGIBLE, '育休復帰による特例', 'hr-1', '2026-09-01T00:00:00+09:00'),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                // 再度自動Assessorを実行しても、再びNotEligibleが出たとする。
                $aggregate->recordAssessment(
                    self::ENTRY, '2025-10-02', '2026-10-01', 200, 100, 0, 50.0, 'v1', ScheduleEntryStatus::NOT_ELIGIBLE,
                );

                $entry = $aggregate->entry(self::ENTRY);
                // Override済みのfinalResult(Eligible)が維持され、ステータスもEligibleのまま。
                $this->assertSame(ScheduleEntryStatus::ELIGIBLE, $entry['status']);
                $this->assertSame(ScheduleEntryStatus::ELIGIBLE, $entry['assessment']['finalResult']);
                // ただし自動判定自体の値は最新化される(比較材料として)。
                $this->assertSame(ScheduleEntryStatus::NOT_ELIGIBLE, $entry['assessment']['automaticResult']);
            });
    }

    // ---- Override ----

    public function test_override_transitions_status_per_final_result(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated(self::ENTRY, '2026-10-01', GrantCategory::REGULAR, 11.0),
                new PaidLeaveScheduleAssessmentRecorded(self::ENTRY, '2025-10-01', '2026-09-30', 200, 100, 0, 50.0, 'v1', ScheduleEntryStatus::NOT_ELIGIBLE),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->overrideAssessment(self::ENTRY, ScheduleEntryStatus::ELIGIBLE, '休職期間を除外して再計算', 'hr-1', '2026-09-01T00:00:00+09:00');

                $entry = $aggregate->entry(self::ENTRY);
                $this->assertSame(ScheduleEntryStatus::ELIGIBLE, $entry['status']);
                $this->assertNotNull($entry['manualOverride']);
            })
            ->assertRecorded([
                new PaidLeaveScheduleAssessmentOverridden(self::ENTRY, ScheduleEntryStatus::ELIGIBLE, '休職期間を除外して再計算', 'hr-1', '2026-09-01T00:00:00+09:00'),
            ]);
    }

    public function test_override_without_prior_assessment_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        PaidLeaveScheduleAggregate::fake(self::USER)
            ->given([new PaidLeaveScheduleEntryCreated(self::ENTRY, '2026-10-01', GrantCategory::REGULAR, 11.0)])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->overrideAssessment(self::ENTRY, ScheduleEntryStatus::ELIGIBLE, '理由', 'hr-1', '2026-09-01T00:00:00+09:00');
            });
    }

    // ---- 手動編集 ----

    public function test_manual_edit_marks_manual_override(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)
            ->given([new PaidLeaveScheduleEntryCreated(self::ENTRY, '2026-10-01', GrantCategory::REGULAR, 11.0)])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->manuallyEditEntry(self::ENTRY, GrantCategory::PROPORTIONAL, 7.0, '契約変更のため', 'hr-1', '2026-09-01T00:00:00+09:00');

                $entry = $aggregate->entry(self::ENTRY);
                $this->assertSame(GrantCategory::PROPORTIONAL, $entry['category']);
                $this->assertSame(7.0, $entry['candidateGrantDays']);
                $this->assertNotNull($entry['manualOverride']);
            })
            ->assertRecorded([
                new PaidLeaveScheduleEntryManuallyEdited(self::ENTRY, GrantCategory::PROPORTIONAL, 7.0, '契約変更のため', 'hr-1', '2026-09-01T00:00:00+09:00'),
            ]);
    }

    // ---- 付与 ----

    public function test_apply_grant_only_works_from_eligible_and_records_grant_id(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated(self::ENTRY, '2026-10-01', GrantCategory::REGULAR, 11.0),
                new PaidLeaveScheduleAssessmentRecorded(self::ENTRY, '2025-10-01', '2026-09-30', 200, 190, 0, 95.0, 'v1', ScheduleEntryStatus::ELIGIBLE),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->applyGrant(self::ENTRY, 'grant-1', 'hr-1');

                $entry = $aggregate->entry(self::ENTRY);
                $this->assertSame(ScheduleEntryStatus::GRANTED, $entry['status']);
                $this->assertSame('grant-1', $entry['grantId']);
            })
            ->assertRecorded([
                new PaidLeaveScheduleEntryGranted(self::ENTRY, 'grant-1', 'hr-1'),
            ]);
    }

    public function test_apply_grant_from_non_eligible_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        PaidLeaveScheduleAggregate::fake(self::USER)
            ->given([new PaidLeaveScheduleEntryCreated(self::ENTRY, '2026-10-01', GrantCategory::REGULAR, 11.0)])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->applyGrant(self::ENTRY, 'grant-1', 'hr-1');
            });
    }

    // ---- 取消 ----

    public function test_cancel_works_from_non_terminal_state(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)
            ->given([new PaidLeaveScheduleEntryCreated(self::ENTRY, '2026-10-01', GrantCategory::REGULAR, 11.0)])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->cancelEntry(self::ENTRY, '退職のため', 'hr-1');

                $this->assertSame(ScheduleEntryStatus::CANCELLED, $aggregate->entry(self::ENTRY)['status']);
            })
            ->assertRecorded([
                new PaidLeaveScheduleEntryCancelled(self::ENTRY, '退職のため', 'hr-1'),
            ]);
    }

    public function test_cancel_from_granted_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        PaidLeaveScheduleAggregate::fake(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated(self::ENTRY, '2026-10-01', GrantCategory::REGULAR, 11.0),
                new PaidLeaveScheduleEntryGranted(self::ENTRY, 'grant-1', 'hr-1'),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->cancelEntry(self::ENTRY, '理由', 'hr-1');
            });
    }
}
