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
use Tests\TestCase;

/**
 * PaidLeaveScheduleAggregateの単体テスト。すべてEvent→Aggregate replay→Commandの結果を
 * 検証する形式で行い、Projection(Eloquent)は一切使わない
 * (docs/changesets/20260906-paid-leave-schedule-assessment/spec.md 検証方法)。
 */
class PaidLeaveScheduleAggregateTest extends TestCase
{
    private const USER = 'user-1';

    // ---- Schedule生成(新入社員・月次ローリング・1年先まで保証) ----

    public function test_new_hire_schedule_generation_creates_all_candidates(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->ensureFutureScheduleGenerated([
                    ['entryId' => 'e1', 'scheduledOn' => '2025-10-01', 'category' => 'normal', 'candidateGrantDays' => 10.0],
                    ['entryId' => 'e2', 'scheduledOn' => '2026-04-01', 'category' => 'normal', 'candidateGrantDays' => 11.0],
                ]);
            })
            ->assertRecorded([
                new PaidLeaveScheduleEntryCreated('e1', '2025-10-01', 'normal', 10.0),
                new PaidLeaveScheduleEntryCreated('e2', '2026-04-01', 'normal', 11.0),
            ]);
    }

    public function test_monthly_rolling_generation_is_idempotent_for_existing_entries(): void
    {
        // 既に存在するscheduledOnはスキップされ、新規分(1年先までの追加ロール)だけ作られる。
        PaidLeaveScheduleAggregate::fake(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated('e1', '2025-10-01', 'normal', 10.0),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->ensureFutureScheduleGenerated([
                    ['entryId' => 'e1-dup', 'scheduledOn' => '2025-10-01', 'category' => 'normal', 'candidateGrantDays' => 10.0],
                    ['entryId' => 'e2', 'scheduledOn' => '2026-10-01', 'category' => 'normal', 'candidateGrantDays' => 11.0],
                ]);
            })
            ->assertRecorded([
                new PaidLeaveScheduleEntryCreated('e2', '2026-10-01', 'normal', 11.0),
            ]);
    }

    public function test_one_year_ahead_horizon_guarantee_generates_missing_far_entry(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated('e1', '2025-10-01', 'normal', 10.0),
                new PaidLeaveScheduleEntryCreated('e2', '2026-04-01', 'normal', 11.0),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                // cronロールが実行され、1年先(2026-10-01)まで存在保証を要求する。
                $aggregate->ensureFutureScheduleGenerated([
                    ['entryId' => 'e1-dup', 'scheduledOn' => '2025-10-01', 'category' => 'normal', 'candidateGrantDays' => 10.0],
                    ['entryId' => 'e2-dup', 'scheduledOn' => '2026-04-01', 'category' => 'normal', 'candidateGrantDays' => 11.0],
                    ['entryId' => 'e3', 'scheduledOn' => '2026-10-01', 'category' => 'normal', 'candidateGrantDays' => 12.0],
                ]);
            })
            ->assertRecorded([
                new PaidLeaveScheduleEntryCreated('e3', '2026-10-01', 'normal', 12.0),
            ]);
    }

    // ---- 再計算(条件変更時・過去確定不変・個別修正保護) ----

    public function test_recalculation_supersedes_entry_when_content_changed(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated('e1', '2025-10-01', 'proportional', 8.0),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->recalculateFutureSchedule([
                    ['entryId' => 'e1-v2', 'scheduledOn' => '2025-10-01', 'category' => 'normal', 'candidateGrantDays' => 10.0],
                ], 'work_style変更');
            })
            ->assertRecorded([
                new PaidLeaveScheduleEntrySuperseded('e1', 'work_style変更', '2025-10-01', 'proportional', 8.0),
                new PaidLeaveScheduleEntryCreated('e1-v2', '2025-10-01', 'normal', 10.0),
            ]);
    }

    public function test_recalculation_is_noop_when_content_unchanged(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated('e1', '2025-10-01', 'normal', 10.0),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->recalculateFutureSchedule([
                    ['entryId' => 'e1-dup', 'scheduledOn' => '2025-10-01', 'category' => 'normal', 'candidateGrantDays' => 10.0],
                ], '定期再計算');
            })
            ->assertNotRecorded([
                PaidLeaveScheduleEntrySuperseded::class,
                PaidLeaveScheduleEntryCreated::class,
            ]);
    }

    public function test_recalculation_cancels_entry_no_longer_needed(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated('e1', '2025-10-01', 'normal', 10.0),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->recalculateFutureSchedule([], '退職予定日確定');
            })
            ->assertRecorded([
                new PaidLeaveScheduleEntryCancelled('e1', '退職予定日確定'),
            ]);
    }

    public function test_past_confirmed_granted_entry_is_immutable_under_recalculation(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated('e1', '2025-10-01', 'proportional', 8.0),
                new PaidLeaveScheduleEntryGranted('e1', 'grant-1', 'admin-1'),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                // 同じscheduledOnで内容が異なる再計算を要求しても、確定済みentryは触らない。
                $aggregate->recalculateFutureSchedule([
                    ['entryId' => 'e1-v2', 'scheduledOn' => '2025-10-01', 'category' => 'normal', 'candidateGrantDays' => 10.0],
                ], '再計算');
            })
            ->assertRecorded([
                // Granted済みのe1は別日付とみなされないため、新規に(同日付だが別)エントリが作られる。
                new PaidLeaveScheduleEntryCreated('e1-v2', '2025-10-01', 'normal', 10.0),
            ]);
    }

    public function test_manually_edited_entry_is_not_silently_overwritten_by_recalculation(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated('e1', '2025-10-01', 'proportional', 8.0),
                new PaidLeaveScheduleEntryManuallyEdited('e1', ['candidateGrantDays' => 9.0], '手動調整', 'admin-1'),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->recalculateFutureSchedule([
                    ['entryId' => 'e1-v2', 'scheduledOn' => '2025-10-01', 'category' => 'normal', 'candidateGrantDays' => 10.0],
                ], '再計算');
            })
            ->assertNotRecorded([
                PaidLeaveScheduleEntrySuperseded::class,
                PaidLeaveScheduleEntryCancelled::class,
                // 保護対象の候補が「残った候補」として二重に新規作成されないことも確認する
                // (同じscheduledOnに対する重複エントリの回帰防止)。
                PaidLeaveScheduleEntryCreated::class,
            ]);
    }

    public function test_manually_edited_entry_is_recreated_when_override_manual_edits_is_true(): void
    {
        // docs/changesets/20260916-paid-leave-policy-change-reapply/spec.md: 法定付与ポリシー・
        // 付与ルール変更トリガーに限り、個別修正済みエントリも保護せず再作成する。
        PaidLeaveScheduleAggregate::fake(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated('e1', '2025-10-01', 'proportional', 8.0),
                new PaidLeaveScheduleEntryManuallyEdited('e1', ['candidateGrantDays' => 9.0], '手動調整', 'admin-1'),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->recalculateFutureSchedule([
                    ['entryId' => 'e1-v2', 'scheduledOn' => '2025-10-01', 'category' => 'normal', 'candidateGrantDays' => 10.0],
                ], '法定付与ポリシーの変更', overrideManualEdits: true);
            })
            ->assertRecorded([
                new PaidLeaveScheduleEntrySuperseded('e1', '法定付与ポリシーの変更', '2025-10-01', 'proportional', 9.0),
                new PaidLeaveScheduleEntryCreated('e1-v2', '2025-10-01', 'normal', 10.0),
            ]);
    }

    public function test_granted_entry_is_still_immutable_even_with_override_manual_edits(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated('e1', '2025-10-01', 'proportional', 8.0),
                new PaidLeaveScheduleEntryGranted('e1', 'grant-1', 'admin-1'),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->recalculateFutureSchedule([], '法定付与ポリシーの変更', overrideManualEdits: true);
            })
            ->assertNotRecorded([
                PaidLeaveScheduleEntrySuperseded::class,
                PaidLeaveScheduleEntryCancelled::class,
            ]);
    }

    public function test_granted_entry_is_superseded_when_include_granted_entries_is_true(): void
    {
        // 過去分洗い替えコマンド専用: includeGrantedEntries=true の場合、
        // Grantedエントリも洗い替え対象に含める(Cancelled のみ保護)。
        PaidLeaveScheduleAggregate::fake(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated('e1', '2025-10-01', 'proportional', 8.0),
                new PaidLeaveScheduleEntryGranted('e1', 'grant-1', 'admin-1'),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->recalculateFutureSchedule(
                    [
                        ['entryId' => 'e1-v2', 'scheduledOn' => '2025-10-01', 'category' => 'normal', 'candidateGrantDays' => 10.0],
                    ],
                    '過去分洗い替え',
                    overrideManualEdits: false,
                    includeGrantedEntries: true
                );
            })
            ->assertRecorded([
                new PaidLeaveScheduleEntrySuperseded('e1', '過去分洗い替え', '2025-10-01', 'proportional', 8.0),
                new PaidLeaveScheduleEntryCreated('e1-v2', '2025-10-01', 'normal', 10.0),
            ]);
    }

    public function test_granted_entry_is_protected_when_include_granted_entries_is_false(): void
    {
        // デフォルト(includeGrantedEntries=false): Grantedエントリは保護。
        PaidLeaveScheduleAggregate::fake(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated('e1', '2025-10-01', 'proportional', 8.0),
                new PaidLeaveScheduleEntryGranted('e1', 'grant-1', 'admin-1'),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->recalculateFutureSchedule(
                    [
                        ['entryId' => 'e1-v2', 'scheduledOn' => '2025-10-01', 'category' => 'normal', 'candidateGrantDays' => 10.0],
                    ],
                    '再計算',
                    overrideManualEdits: false,
                    includeGrantedEntries: false  // 明示的に指定(デフォルトと同じ)
                );
            })
            ->assertNotRecorded([
                PaidLeaveScheduleEntrySuperseded::class,
                PaidLeaveScheduleEntryCancelled::class,
            ]);
    }

    public function test_cancelled_entry_is_always_protected_even_with_include_granted_entries(): void
    {
        // Cancelled エントリは includeGrantedEntries の値に関わらず常に保護。
        PaidLeaveScheduleAggregate::fake(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated('e1', '2025-10-01', 'proportional', 8.0),
                new PaidLeaveScheduleEntryCancelled('e1', '一度目の取消'),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->recalculateFutureSchedule(
                    [
                        ['entryId' => 'e1-v2', 'scheduledOn' => '2025-10-01', 'category' => 'normal', 'candidateGrantDays' => 10.0],
                    ],
                    '再計算',
                    overrideManualEdits: false,
                    includeGrantedEntries: true
                );
            })
            ->assertRecorded([
                new PaidLeaveScheduleEntryCreated('e1-v2', '2025-10-01', 'normal', 10.0),
            ]);
    }

    // ---- Assessment ----

    public function test_attendance_rate_assessment_eligible_transitions_state(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated('e1', '2025-10-01', 'normal', 10.0),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->runAttendanceRateAssessment(
                    'e1', 'a1', '2025-04-01', '2025-10-01', 20, 18, 0, 90.0, 'v1',
                    PaidLeaveScheduleAggregate::STATUS_ELIGIBLE,
                );
            })
            ->assertRecorded([
                new PaidLeaveScheduleAssessmentRecorded('e1', 'a1', '2025-04-01', '2025-10-01', 20, 18, 0, 90.0, 'v1', PaidLeaveScheduleAggregate::STATUS_ELIGIBLE),
            ]);
    }

    public function test_assessment_below_80_percent_is_not_eligible(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated('e1', '2025-10-01', 'normal', 10.0),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->runAttendanceRateAssessment(
                    'e1', 'a1', '2025-04-01', '2025-10-01', 20, 10, 0, 50.0, 'v1',
                    PaidLeaveScheduleAggregate::STATUS_NOT_ELIGIBLE,
                );
            })
            ->assertRecorded([
                new PaidLeaveScheduleAssessmentRecorded('e1', 'a1', '2025-04-01', '2025-10-01', 20, 10, 0, 50.0, 'v1', PaidLeaveScheduleAggregate::STATUS_NOT_ELIGIBLE),
            ]);
    }

    public function test_needs_review_assessment_result_is_recorded(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated('e1', '2025-10-01', 'normal', 10.0),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->runAttendanceRateAssessment(
                    'e1', 'a1', '2025-04-01', '2025-10-01', 0, 0, 0, null, 'v1',
                    PaidLeaveScheduleAggregate::STATUS_NEEDS_REVIEW,
                );
            })
            ->assertRecorded([
                new PaidLeaveScheduleAssessmentRecorded('e1', 'a1', '2025-04-01', '2025-10-01', 0, 0, 0, null, 'v1', PaidLeaveScheduleAggregate::STATUS_NEEDS_REVIEW),
            ]);
    }

    public function test_assessment_on_granted_entry_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        PaidLeaveScheduleAggregate::fake(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated('e1', '2025-10-01', 'normal', 10.0),
                new PaidLeaveScheduleEntryGranted('e1', 'grant-1', 'admin-1'),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->runAttendanceRateAssessment('e1', 'a1', '2025-04-01', '2025-10-01', 20, 18, 0, 90.0, 'v1', PaidLeaveScheduleAggregate::STATUS_ELIGIBLE);
            });
    }

    // ---- Override ----

    public function test_override_not_eligible_to_eligible(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated('e1', '2025-10-01', 'normal', 10.0),
                new PaidLeaveScheduleAssessmentRecorded('e1', 'a1', '2025-04-01', '2025-10-01', 20, 10, 0, 50.0, 'v1', PaidLeaveScheduleAggregate::STATUS_NOT_ELIGIBLE),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->overrideScheduleAssessment('e1', PaidLeaveScheduleAggregate::STATUS_ELIGIBLE, '育休からの復職を考慮', 'admin-1');
            })
            ->assertRecorded([
                new PaidLeaveScheduleAssessmentOverridden('e1', 'a1', PaidLeaveScheduleAggregate::STATUS_ELIGIBLE, '育休からの復職を考慮', 'admin-1'),
            ]);
    }

    public function test_override_without_prior_assessment_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        PaidLeaveScheduleAggregate::fake(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated('e1', '2025-10-01', 'normal', 10.0),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->overrideScheduleAssessment('e1', PaidLeaveScheduleAggregate::STATUS_ELIGIBLE, '理由', 'admin-1');
            });
    }

    // ---- 個別修正 ----

    public function test_manual_edit_is_recorded_and_protected(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated('e1', '2025-10-01', 'proportional', 8.0),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->manuallyEditScheduleEntry('e1', ['candidateGrantDays' => 9.0], '個別事情による調整', 'admin-1');
            })
            ->assertRecorded([
                new PaidLeaveScheduleEntryManuallyEdited('e1', ['candidateGrantDays' => 9.0], '個別事情による調整', 'admin-1'),
            ]);
    }

    public function test_manual_edit_on_cancelled_entry_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        PaidLeaveScheduleAggregate::fake(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated('e1', '2025-10-01', 'proportional', 8.0),
                new PaidLeaveScheduleEntryCancelled('e1', '対象外'),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->manuallyEditScheduleEntry('e1', ['candidateGrantDays' => 9.0], '調整', 'admin-1');
            });
    }

    // ---- 付与(ApplyScheduledGrants経由) ----

    public function test_grant_entry_transitions_eligible_to_granted(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated('e1', '2025-10-01', 'normal', 10.0),
                new PaidLeaveScheduleAssessmentRecorded('e1', 'a1', '2025-04-01', '2025-10-01', 20, 18, 0, 90.0, 'v1', PaidLeaveScheduleAggregate::STATUS_ELIGIBLE),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->grantEntry('e1', 'grant-1', 'admin-1');
            })
            ->assertRecorded([
                new PaidLeaveScheduleEntryGranted('e1', 'grant-1', 'admin-1'),
            ]);
    }

    public function test_grant_entry_on_not_eligible_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        PaidLeaveScheduleAggregate::fake(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated('e1', '2025-10-01', 'normal', 10.0),
                new PaidLeaveScheduleAssessmentRecorded('e1', 'a1', '2025-04-01', '2025-10-01', 20, 10, 0, 50.0, 'v1', PaidLeaveScheduleAggregate::STATUS_NOT_ELIGIBLE),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->grantEntry('e1', 'grant-1', 'admin-1');
            });
    }

    public function test_grant_entry_twice_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        PaidLeaveScheduleAggregate::fake(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated('e1', '2025-10-01', 'normal', 10.0),
                new PaidLeaveScheduleAssessmentRecorded('e1', 'a1', '2025-04-01', '2025-10-01', 20, 18, 0, 90.0, 'v1', PaidLeaveScheduleAggregate::STATUS_ELIGIBLE),
                new PaidLeaveScheduleEntryGranted('e1', 'grant-1', 'admin-1'),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->grantEntry('e1', 'grant-2', 'admin-1');
            });
    }

    // ---- 取消 ----

    public function test_cancel_entry_is_recorded(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated('e1', '2025-10-01', 'normal', 10.0),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->cancelEntry('e1', '退職確定');
            })
            ->assertRecorded([
                new PaidLeaveScheduleEntryCancelled('e1', '退職確定'),
            ]);
    }

    public function test_cancel_already_granted_entry_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        PaidLeaveScheduleAggregate::fake(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated('e1', '2025-10-01', 'normal', 10.0),
                new PaidLeaveScheduleEntryGranted('e1', 'grant-1', 'admin-1'),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->cancelEntry('e1', '取消');
            });
    }

    // ---- entry()状態確認 ----

    public function test_entry_state_reflects_assessment_and_final_result(): void
    {
        $capturedEntry = null;

        PaidLeaveScheduleAggregate::fake(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated('e1', '2025-10-01', 'normal', 10.0),
                new PaidLeaveScheduleAssessmentRecorded('e1', 'a1', '2025-04-01', '2025-10-01', 20, 10, 0, 50.0, 'v1', PaidLeaveScheduleAggregate::STATUS_NOT_ELIGIBLE),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) use (&$capturedEntry) {
                $aggregate->overrideScheduleAssessment('e1', PaidLeaveScheduleAggregate::STATUS_ELIGIBLE, '復職考慮', 'admin-1');
                $capturedEntry = $aggregate->entry('e1');
            });

        $this->assertSame(PaidLeaveScheduleAggregate::STATUS_ELIGIBLE, $capturedEntry['status']);
        $this->assertSame(PaidLeaveScheduleAggregate::STATUS_ELIGIBLE, $capturedEntry['assessments']['a1']['finalResult']);
        $this->assertSame(PaidLeaveScheduleAggregate::STATUS_NOT_ELIGIBLE, $capturedEntry['assessments']['a1']['automaticResult']);
    }
}
