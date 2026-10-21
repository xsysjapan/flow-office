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

    // ---- 集約ID・社員の束縛 ----

    public function test_stream_id_is_derived_from_the_user_id_and_differs_from_it(): void
    {
        $streamId = PaidLeaveScheduleAggregate::streamIdFor(self::USER);

        $this->assertNotSame(self::USER, $streamId);
        $this->assertSame($streamId, PaidLeaveScheduleAggregate::streamIdFor(self::USER));
        $this->assertNotSame($streamId, PaidLeaveScheduleAggregate::streamIdFor('user-2'));
    }

    public function test_recorded_events_carry_the_bound_user_id(): void
    {
        PaidLeaveScheduleAggregate::fake(PaidLeaveScheduleAggregate::streamIdFor('user-2'))->forUser('user-2')
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->ensureFutureScheduleGenerated([
                    ['entryId' => 'e1', 'scheduledOn' => '2025-10-01', 'category' => 'normal', 'candidateGrantDays' => 10.0],
                ]);
            })
            ->assertRecorded([
                new PaidLeaveScheduleEntryCreated('user-2', 'e1', '2025-10-01', 'normal', 10.0),
            ]);
    }

    public function test_operating_without_binding_a_user_is_rejected(): void
    {
        $this->expectException(\LogicException::class);

        PaidLeaveScheduleAggregate::fake(self::USER)
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->ensureFutureScheduleGenerated([
                    ['entryId' => 'e1', 'scheduledOn' => '2025-10-01', 'category' => 'normal', 'candidateGrantDays' => 10.0],
                ]);
            });
    }

    // ---- Schedule生成(新入社員・月次ローリング・1年先まで保証) ----

    public function test_new_hire_schedule_generation_creates_all_candidates(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)->forUser(self::USER)
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->ensureFutureScheduleGenerated([
                    ['entryId' => 'e1', 'scheduledOn' => '2025-10-01', 'category' => 'normal', 'candidateGrantDays' => 10.0],
                    ['entryId' => 'e2', 'scheduledOn' => '2026-04-01', 'category' => 'normal', 'candidateGrantDays' => 11.0],
                ]);
            })
            ->assertRecorded([
                new PaidLeaveScheduleEntryCreated(self::USER, 'e1', '2025-10-01', 'normal', 10.0),
                new PaidLeaveScheduleEntryCreated(self::USER, 'e2', '2026-04-01', 'normal', 11.0),
            ]);
    }

    public function test_monthly_rolling_generation_is_idempotent_for_existing_entries(): void
    {
        // 既に存在するscheduledOnはスキップされ、新規分(1年先までの追加ロール)だけ作られる。
        PaidLeaveScheduleAggregate::fake(self::USER)->forUser(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated(self::USER, 'e1', '2025-10-01', 'normal', 10.0),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->ensureFutureScheduleGenerated([
                    ['entryId' => 'e1-dup', 'scheduledOn' => '2025-10-01', 'category' => 'normal', 'candidateGrantDays' => 10.0],
                    ['entryId' => 'e2', 'scheduledOn' => '2026-10-01', 'category' => 'normal', 'candidateGrantDays' => 11.0],
                ]);
            })
            ->assertRecorded([
                new PaidLeaveScheduleEntryCreated(self::USER, 'e2', '2026-10-01', 'normal', 11.0),
            ]);
    }

    public function test_one_year_ahead_horizon_guarantee_generates_missing_far_entry(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)->forUser(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated(self::USER, 'e1', '2025-10-01', 'normal', 10.0),
                new PaidLeaveScheduleEntryCreated(self::USER, 'e2', '2026-04-01', 'normal', 11.0),
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
                new PaidLeaveScheduleEntryCreated(self::USER, 'e3', '2026-10-01', 'normal', 12.0),
            ]);
    }

    // ---- 再計算(条件変更時・過去確定不変・個別修正保護) ----

    public function test_recalculation_supersedes_entry_when_content_changed(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)->forUser(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated(self::USER, 'e1', '2025-10-01', 'proportional', 8.0),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->recalculateFutureSchedule([
                    ['entryId' => 'e1-v2', 'scheduledOn' => '2025-10-01', 'category' => 'normal', 'candidateGrantDays' => 10.0],
                ], 'work_style変更');
            })
            ->assertRecorded([
                new PaidLeaveScheduleEntrySuperseded(self::USER, 'e1', 'work_style変更', '2025-10-01', 'proportional', 8.0),
                new PaidLeaveScheduleEntryCreated(self::USER, 'e1-v2', '2025-10-01', 'normal', 10.0),
            ]);
    }

    public function test_recalculation_is_noop_when_content_unchanged(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)->forUser(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated(self::USER, 'e1', '2025-10-01', 'normal', 10.0),
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
        PaidLeaveScheduleAggregate::fake(self::USER)->forUser(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated(self::USER, 'e1', '2025-10-01', 'normal', 10.0),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->recalculateFutureSchedule([], '退職予定日確定');
            })
            ->assertRecorded([
                new PaidLeaveScheduleEntryCancelled(self::USER, 'e1', '退職予定日確定'),
            ]);
    }

    public function test_past_confirmed_granted_entry_is_immutable_under_recalculation(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)->forUser(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated(self::USER, 'e1', '2025-10-01', 'proportional', 8.0),
                new PaidLeaveScheduleEntryGranted(self::USER, 'e1', 'grant-1', 'admin-1'),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                // 同じscheduledOnで内容が異なる再計算を要求しても、確定済みentryは触らない。
                $aggregate->recalculateFutureSchedule([
                    ['entryId' => 'e1-v2', 'scheduledOn' => '2025-10-01', 'category' => 'normal', 'candidateGrantDays' => 10.0],
                ], '再計算');
            })
            ->assertNotRecorded([
                PaidLeaveScheduleEntrySuperseded::class,
                PaidLeaveScheduleEntryCancelled::class,
                // 付与済みのe1と同じ日付の候補は消費され、重複した予定は作られない。
                PaidLeaveScheduleEntryCreated::class,
            ]);
    }

    public function test_manually_edited_entry_is_not_silently_overwritten_by_recalculation(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)->forUser(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated(self::USER, 'e1', '2025-10-01', 'proportional', 8.0),
                new PaidLeaveScheduleEntryManuallyEdited(self::USER, 'e1', ['candidateGrantDays' => 9.0], '手動調整', 'admin-1'),
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
        PaidLeaveScheduleAggregate::fake(self::USER)->forUser(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated(self::USER, 'e1', '2025-10-01', 'proportional', 8.0),
                new PaidLeaveScheduleEntryManuallyEdited(self::USER, 'e1', ['candidateGrantDays' => 9.0], '手動調整', 'admin-1'),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->recalculateFutureSchedule([
                    ['entryId' => 'e1-v2', 'scheduledOn' => '2025-10-01', 'category' => 'normal', 'candidateGrantDays' => 10.0],
                ], '法定付与ポリシーの変更', overrideManualEdits: true);
            })
            ->assertRecorded([
                new PaidLeaveScheduleEntrySuperseded(self::USER, 'e1', '法定付与ポリシーの変更', '2025-10-01', 'proportional', 9.0),
                new PaidLeaveScheduleEntryCreated(self::USER, 'e1-v2', '2025-10-01', 'normal', 10.0),
            ]);
    }

    public function test_granted_entry_is_still_immutable_even_with_override_manual_edits(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)->forUser(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated(self::USER, 'e1', '2025-10-01', 'proportional', 8.0),
                new PaidLeaveScheduleEntryGranted(self::USER, 'e1', 'grant-1', 'admin-1'),
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
        PaidLeaveScheduleAggregate::fake(self::USER)->forUser(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated(self::USER, 'e1', '2025-10-01', 'proportional', 8.0),
                new PaidLeaveScheduleEntryGranted(self::USER, 'e1', 'grant-1', 'admin-1'),
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
                new PaidLeaveScheduleEntrySuperseded(self::USER, 'e1', '過去分洗い替え', '2025-10-01', 'proportional', 8.0),
                new PaidLeaveScheduleEntryCreated(self::USER, 'e1-v2', '2025-10-01', 'normal', 10.0),
            ]);
    }

    public function test_granted_entry_is_protected_when_include_granted_entries_is_false(): void
    {
        // デフォルト(includeGrantedEntries=false): Grantedエントリは保護。
        PaidLeaveScheduleAggregate::fake(self::USER)->forUser(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated(self::USER, 'e1', '2025-10-01', 'proportional', 8.0),
                new PaidLeaveScheduleEntryGranted(self::USER, 'e1', 'grant-1', 'admin-1'),
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
        PaidLeaveScheduleAggregate::fake(self::USER)->forUser(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated(self::USER, 'e1', '2025-10-01', 'proportional', 8.0),
                new PaidLeaveScheduleEntryCancelled(self::USER, 'e1', '一度目の取消'),
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
                new PaidLeaveScheduleEntryCreated(self::USER, 'e1-v2', '2025-10-01', 'normal', 10.0),
            ]);
    }

    // ---- 再計算の対象期間(fromDate)と付与済みの行 ----

    public function test_unconfirmed_entry_before_from_date_is_left_untouched(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)->forUser(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated(self::USER, 'past', '2026-09-01', 'proportional', 8.0),
                new PaidLeaveScheduleEntryCreated(self::USER, 'past-eligible', '2026-10-01', 'proportional', 8.0),
                new PaidLeaveScheduleAssessmentRecorded(self::USER, 'past-eligible', 'a1', '2026-04-01', '2026-10-01', 20, 18, 0, 90.0, 'v1', PaidLeaveScheduleAggregate::STATUS_ELIGIBLE),
                new PaidLeaveScheduleEntryCreated(self::USER, 'future', '2026-11-01', 'proportional', 8.0),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                // 候補は今日以降のみ。過去日付の行は状態に関わらず取消・置換されず、未来の行だけが取消になる。
                $aggregate->recalculateFutureSchedule([], '入社日の変更', fromDate: '2026-10-21');
            })
            ->assertRecorded([
                new PaidLeaveScheduleEntryCancelled(self::USER, 'future', '入社日の変更'),
            ]);
    }

    public function test_candidate_on_same_date_as_granted_entry_is_not_created(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)->forUser(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated(self::USER, 'e1', '2026-10-21', 'normal', 10.0),
                new PaidLeaveScheduleEntryGranted(self::USER, 'e1', 'grant-1', 'admin-1'),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->recalculateFutureSchedule([
                    ['entryId' => 'e1-dup', 'scheduledOn' => '2026-10-21', 'category' => 'normal', 'candidateGrantDays' => 10.0],
                    ['entryId' => 'e2', 'scheduledOn' => '2027-10-21', 'category' => 'normal', 'candidateGrantDays' => 11.0],
                ], '入社日の変更', fromDate: '2026-10-21');
            })
            ->assertRecorded([
                new PaidLeaveScheduleEntryCreated(self::USER, 'e2', '2027-10-21', 'normal', 11.0),
            ]);
    }

    public function test_candidate_on_same_date_as_cancelled_entry_is_created(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)->forUser(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated(self::USER, 'e1', '2026-10-21', 'normal', 10.0),
                new PaidLeaveScheduleEntryCancelled(self::USER, 'e1', '自動付与の無効化'),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->recalculateFutureSchedule([
                    ['entryId' => 'e1-v2', 'scheduledOn' => '2026-10-21', 'category' => 'normal', 'candidateGrantDays' => 10.0],
                ], '自動付与の有効化', fromDate: '2026-10-21');
            })
            ->assertRecorded([
                new PaidLeaveScheduleEntryCreated(self::USER, 'e1-v2', '2026-10-21', 'normal', 10.0),
            ]);
    }

    public function test_include_granted_entries_still_supersedes_granted_entry_on_or_after_from_date(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)->forUser(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated(self::USER, 'e1', '2026-10-21', 'proportional', 8.0),
                new PaidLeaveScheduleEntryGranted(self::USER, 'e1', 'grant-1', 'admin-1'),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->recalculateFutureSchedule(
                    [
                        ['entryId' => 'e1-v2', 'scheduledOn' => '2026-10-21', 'category' => 'normal', 'candidateGrantDays' => 10.0],
                    ],
                    '過去分洗い替え',
                    overrideManualEdits: true,
                    includeGrantedEntries: true,
                    fromDate: '2026-10-01',
                );
            })
            ->assertRecorded([
                new PaidLeaveScheduleEntrySuperseded(self::USER, 'e1', '過去分洗い替え', '2026-10-21', 'proportional', 8.0),
                new PaidLeaveScheduleEntryCreated(self::USER, 'e1-v2', '2026-10-21', 'normal', 10.0),
            ]);
    }

    public function test_entry_before_from_date_is_untouched_even_with_include_granted_entries(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)->forUser(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated(self::USER, 'e1', '2026-09-01', 'proportional', 8.0),
                new PaidLeaveScheduleEntryGranted(self::USER, 'e1', 'grant-1', 'admin-1'),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->recalculateFutureSchedule([], '過去分洗い替え', includeGrantedEntries: true, fromDate: '2026-10-01');
            })
            ->assertNotRecorded([
                PaidLeaveScheduleEntrySuperseded::class,
                PaidLeaveScheduleEntryCancelled::class,
            ]);
    }

    // ---- Assessment ----

    public function test_attendance_rate_assessment_eligible_transitions_state(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)->forUser(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated(self::USER, 'e1', '2025-10-01', 'normal', 10.0),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->runAttendanceRateAssessment(
                    'e1', 'a1', '2025-04-01', '2025-10-01', 20, 18, 0, 90.0, 'v1',
                    PaidLeaveScheduleAggregate::STATUS_ELIGIBLE,
                );
            })
            ->assertRecorded([
                new PaidLeaveScheduleAssessmentRecorded(self::USER, 'e1', 'a1', '2025-04-01', '2025-10-01', 20, 18, 0, 90.0, 'v1', PaidLeaveScheduleAggregate::STATUS_ELIGIBLE),
            ]);
    }

    public function test_assessment_below_80_percent_is_not_eligible(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)->forUser(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated(self::USER, 'e1', '2025-10-01', 'normal', 10.0),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->runAttendanceRateAssessment(
                    'e1', 'a1', '2025-04-01', '2025-10-01', 20, 10, 0, 50.0, 'v1',
                    PaidLeaveScheduleAggregate::STATUS_NOT_ELIGIBLE,
                );
            })
            ->assertRecorded([
                new PaidLeaveScheduleAssessmentRecorded(self::USER, 'e1', 'a1', '2025-04-01', '2025-10-01', 20, 10, 0, 50.0, 'v1', PaidLeaveScheduleAggregate::STATUS_NOT_ELIGIBLE),
            ]);
    }

    public function test_needs_review_assessment_result_is_recorded(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)->forUser(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated(self::USER, 'e1', '2025-10-01', 'normal', 10.0),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->runAttendanceRateAssessment(
                    'e1', 'a1', '2025-04-01', '2025-10-01', 0, 0, 0, null, 'v1',
                    PaidLeaveScheduleAggregate::STATUS_NEEDS_REVIEW,
                );
            })
            ->assertRecorded([
                new PaidLeaveScheduleAssessmentRecorded(self::USER, 'e1', 'a1', '2025-04-01', '2025-10-01', 0, 0, 0, null, 'v1', PaidLeaveScheduleAggregate::STATUS_NEEDS_REVIEW),
            ]);
    }

    public function test_assessment_on_granted_entry_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        PaidLeaveScheduleAggregate::fake(self::USER)->forUser(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated(self::USER, 'e1', '2025-10-01', 'normal', 10.0),
                new PaidLeaveScheduleEntryGranted(self::USER, 'e1', 'grant-1', 'admin-1'),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->runAttendanceRateAssessment('e1', 'a1', '2025-04-01', '2025-10-01', 20, 18, 0, 90.0, 'v1', PaidLeaveScheduleAggregate::STATUS_ELIGIBLE);
            });
    }

    // ---- Override ----

    public function test_override_not_eligible_to_eligible(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)->forUser(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated(self::USER, 'e1', '2025-10-01', 'normal', 10.0),
                new PaidLeaveScheduleAssessmentRecorded(self::USER, 'e1', 'a1', '2025-04-01', '2025-10-01', 20, 10, 0, 50.0, 'v1', PaidLeaveScheduleAggregate::STATUS_NOT_ELIGIBLE),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->overrideScheduleAssessment('e1', PaidLeaveScheduleAggregate::STATUS_ELIGIBLE, '育休からの復職を考慮', 'admin-1');
            })
            ->assertRecorded([
                new PaidLeaveScheduleAssessmentOverridden(self::USER, 'e1', 'a1', PaidLeaveScheduleAggregate::STATUS_ELIGIBLE, '育休からの復職を考慮', 'admin-1'),
            ]);
    }

    public function test_override_without_prior_assessment_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        PaidLeaveScheduleAggregate::fake(self::USER)->forUser(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated(self::USER, 'e1', '2025-10-01', 'normal', 10.0),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->overrideScheduleAssessment('e1', PaidLeaveScheduleAggregate::STATUS_ELIGIBLE, '理由', 'admin-1');
            });
    }

    // ---- 個別修正 ----

    public function test_manual_edit_is_recorded_and_protected(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)->forUser(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated(self::USER, 'e1', '2025-10-01', 'proportional', 8.0),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->manuallyEditScheduleEntry('e1', ['candidateGrantDays' => 9.0], '個別事情による調整', 'admin-1');
            })
            ->assertRecorded([
                new PaidLeaveScheduleEntryManuallyEdited(self::USER, 'e1', ['candidateGrantDays' => 9.0], '個別事情による調整', 'admin-1'),
            ]);
    }

    public function test_manual_edit_on_cancelled_entry_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        PaidLeaveScheduleAggregate::fake(self::USER)->forUser(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated(self::USER, 'e1', '2025-10-01', 'proportional', 8.0),
                new PaidLeaveScheduleEntryCancelled(self::USER, 'e1', '対象外'),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->manuallyEditScheduleEntry('e1', ['candidateGrantDays' => 9.0], '調整', 'admin-1');
            });
    }

    public function test_manual_edit_with_disallowed_key_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        PaidLeaveScheduleAggregate::fake(self::USER)->forUser(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated(self::USER, 'e1', '2025-10-01', 'proportional', 8.0),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->manuallyEditScheduleEntry('e1', ['scheduledOn' => '2025-10-02'], '日付を動かす', 'admin-1');
            });
    }

    public function test_manual_edit_with_empty_changes_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        PaidLeaveScheduleAggregate::fake(self::USER)->forUser(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated(self::USER, 'e1', '2025-10-01', 'proportional', 8.0),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->manuallyEditScheduleEntry('e1', [], '空の修正', 'admin-1');
            });
    }

    public function test_manual_edit_on_granted_entry_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        PaidLeaveScheduleAggregate::fake(self::USER)->forUser(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated(self::USER, 'e1', '2025-10-01', 'proportional', 8.0),
                new PaidLeaveScheduleEntryGranted(self::USER, 'e1', 'grant-1', 'admin-1'),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->manuallyEditScheduleEntry('e1', ['candidateGrantDays' => 9.0], '付与後の修正', 'admin-1');
            });
    }

    // ---- 付与(ApplyScheduledGrants経由) ----

    public function test_grant_entry_transitions_eligible_to_granted(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)->forUser(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated(self::USER, 'e1', '2025-10-01', 'normal', 10.0),
                new PaidLeaveScheduleAssessmentRecorded(self::USER, 'e1', 'a1', '2025-04-01', '2025-10-01', 20, 18, 0, 90.0, 'v1', PaidLeaveScheduleAggregate::STATUS_ELIGIBLE),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->grantEntry('e1', 'grant-1', 'admin-1');
            })
            ->assertRecorded([
                new PaidLeaveScheduleEntryGranted(self::USER, 'e1', 'grant-1', 'admin-1'),
            ]);
    }

    public function test_grant_entry_on_not_eligible_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        PaidLeaveScheduleAggregate::fake(self::USER)->forUser(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated(self::USER, 'e1', '2025-10-01', 'normal', 10.0),
                new PaidLeaveScheduleAssessmentRecorded(self::USER, 'e1', 'a1', '2025-04-01', '2025-10-01', 20, 10, 0, 50.0, 'v1', PaidLeaveScheduleAggregate::STATUS_NOT_ELIGIBLE),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->grantEntry('e1', 'grant-1', 'admin-1');
            });
    }

    public function test_grant_entry_twice_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        PaidLeaveScheduleAggregate::fake(self::USER)->forUser(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated(self::USER, 'e1', '2025-10-01', 'normal', 10.0),
                new PaidLeaveScheduleAssessmentRecorded(self::USER, 'e1', 'a1', '2025-04-01', '2025-10-01', 20, 18, 0, 90.0, 'v1', PaidLeaveScheduleAggregate::STATUS_ELIGIBLE),
                new PaidLeaveScheduleEntryGranted(self::USER, 'e1', 'grant-1', 'admin-1'),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->grantEntry('e1', 'grant-2', 'admin-1');
            });
    }

    // ---- 取消 ----

    public function test_cancel_entry_is_recorded(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)->forUser(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated(self::USER, 'e1', '2025-10-01', 'normal', 10.0),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->cancelEntry('e1', '退職確定');
            })
            ->assertRecorded([
                new PaidLeaveScheduleEntryCancelled(self::USER, 'e1', '退職確定'),
            ]);
    }

    public function test_cancel_already_granted_entry_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        PaidLeaveScheduleAggregate::fake(self::USER)->forUser(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated(self::USER, 'e1', '2025-10-01', 'normal', 10.0),
                new PaidLeaveScheduleEntryGranted(self::USER, 'e1', 'grant-1', 'admin-1'),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->cancelEntry('e1', '取消');
            });
    }

    // ---- entry()状態確認 ----

    public function test_entry_state_reflects_assessment_and_final_result(): void
    {
        $capturedEntry = null;

        PaidLeaveScheduleAggregate::fake(self::USER)->forUser(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated(self::USER, 'e1', '2025-10-01', 'normal', 10.0),
                new PaidLeaveScheduleAssessmentRecorded(self::USER, 'e1', 'a1', '2025-04-01', '2025-10-01', 20, 10, 0, 50.0, 'v1', PaidLeaveScheduleAggregate::STATUS_NOT_ELIGIBLE),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) use (&$capturedEntry) {
                $aggregate->overrideScheduleAssessment('e1', PaidLeaveScheduleAggregate::STATUS_ELIGIBLE, '復職考慮', 'admin-1');
                $capturedEntry = $aggregate->entry('e1');
            });

        $this->assertSame(PaidLeaveScheduleAggregate::STATUS_ELIGIBLE, $capturedEntry['status']);
        $this->assertSame(PaidLeaveScheduleAggregate::STATUS_ELIGIBLE, $capturedEntry['assessments']['a1']['finalResult']);
        $this->assertSame(PaidLeaveScheduleAggregate::STATUS_NOT_ELIGIBLE, $capturedEntry['assessments']['a1']['automaticResult']);
    }

    // ---- 同じ日に名目の継続勤務月数の違う行を持つ(docs/changesets/20261021-paid-leave-same-day-grants/spec.md 論点4) ----

    public function test_ensure_creates_two_entries_on_the_same_day_with_different_nominal_months(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)->forUser(self::USER)
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->ensureFutureScheduleGenerated([
                    ['entryId' => 'e6', 'scheduledOn' => '2026-04-01', 'category' => 'normal', 'candidateGrantDays' => 10.0, 'nominalServiceMonths' => 6],
                    ['entryId' => 'e18', 'scheduledOn' => '2026-04-01', 'category' => 'normal', 'candidateGrantDays' => 11.0, 'nominalServiceMonths' => 18],
                ]);
            })
            ->assertRecorded([
                new PaidLeaveScheduleEntryCreated(self::USER, 'e6', '2026-04-01', 'normal', 10.0, true, 6),
                new PaidLeaveScheduleEntryCreated(self::USER, 'e18', '2026-04-01', 'normal', 11.0, true, 18),
            ]);
    }

    public function test_ensure_is_idempotent_for_the_same_day_and_nominal_months(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)->forUser(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated(self::USER, 'e6', '2026-04-01', 'normal', 10.0, true, 6),
                new PaidLeaveScheduleEntryCreated(self::USER, 'e18', '2026-04-01', 'normal', 11.0, true, 18),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->ensureFutureScheduleGenerated([
                    ['entryId' => 'x6', 'scheduledOn' => '2026-04-01', 'category' => 'normal', 'candidateGrantDays' => 10.0, 'nominalServiceMonths' => 6],
                    ['entryId' => 'x18', 'scheduledOn' => '2026-04-01', 'category' => 'normal', 'candidateGrantDays' => 11.0, 'nominalServiceMonths' => 18],
                ]);
            })
            ->assertNothingRecorded();
    }

    public function test_recalculation_creates_two_entries_on_the_same_day_and_is_idempotent(): void
    {
        $candidates = [
            ['entryId' => 'e6', 'scheduledOn' => '2026-04-01', 'category' => 'normal', 'candidateGrantDays' => 10.0, 'nominalServiceMonths' => 6],
            ['entryId' => 'e18', 'scheduledOn' => '2026-04-01', 'category' => 'normal', 'candidateGrantDays' => 11.0, 'nominalServiceMonths' => 18],
        ];

        PaidLeaveScheduleAggregate::fake(self::USER)->forUser(self::USER)
            ->when(fn (PaidLeaveScheduleAggregate $aggregate) => $aggregate->recalculateFutureSchedule($candidates, '条件変更'))
            ->assertRecorded([
                new PaidLeaveScheduleEntryCreated(self::USER, 'e6', '2026-04-01', 'normal', 10.0, true, 6),
                new PaidLeaveScheduleEntryCreated(self::USER, 'e18', '2026-04-01', 'normal', 11.0, true, 18),
            ]);

        $again = array_map(fn (array $c) => ['entryId' => 'again-'.$c['entryId']] + $c, $candidates);
        PaidLeaveScheduleAggregate::fake(self::USER)->forUser(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated(self::USER, 'e6', '2026-04-01', 'normal', 10.0, true, 6),
                new PaidLeaveScheduleEntryCreated(self::USER, 'e18', '2026-04-01', 'normal', 11.0, true, 18),
            ])
            ->when(fn (PaidLeaveScheduleAggregate $aggregate) => $aggregate->recalculateFutureSchedule($again, '条件変更'))
            ->assertNothingRecorded();
    }

    public function test_recalculation_supersedes_only_the_row_whose_content_changed_and_keeps_previous_nominal_months(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)->forUser(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated(self::USER, 'e6', '2026-04-01', 'normal', 10.0, true, 6),
                new PaidLeaveScheduleEntryCreated(self::USER, 'e18', '2026-04-01', 'normal', 11.0, true, 18),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->recalculateFutureSchedule([
                    ['entryId' => 'n6', 'scheduledOn' => '2026-04-01', 'category' => 'normal', 'candidateGrantDays' => 10.0, 'nominalServiceMonths' => 6],
                    ['entryId' => 'n18', 'scheduledOn' => '2026-04-01', 'category' => 'normal', 'candidateGrantDays' => 12.0, 'nominalServiceMonths' => 18],
                ], '付与ルール変更');
            })
            ->assertRecorded([
                new PaidLeaveScheduleEntrySuperseded(self::USER, 'e18', '付与ルール変更', '2026-04-01', 'normal', 11.0, 18),
                new PaidLeaveScheduleEntryCreated(self::USER, 'n18', '2026-04-01', 'normal', 12.0, true, 18),
            ]);
    }

    public function test_granted_entry_consumption_is_done_per_day_and_nominal_months_pair(): void
    {
        // 同じ日の名目6が付与済みでも、名目18の候補は作られる。名目6の候補だけが消費される。
        PaidLeaveScheduleAggregate::fake(self::USER)->forUser(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated(self::USER, 'e6', '2026-04-01', 'normal', 10.0, true, 6),
                new PaidLeaveScheduleEntryGranted(self::USER, 'e6', 'grant-1', 'admin-1'),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->recalculateFutureSchedule([
                    ['entryId' => 'n6', 'scheduledOn' => '2026-04-01', 'category' => 'normal', 'candidateGrantDays' => 10.0, 'nominalServiceMonths' => 6],
                    ['entryId' => 'n18', 'scheduledOn' => '2026-04-01', 'category' => 'normal', 'candidateGrantDays' => 11.0, 'nominalServiceMonths' => 18],
                ], '再計算');
            })
            ->assertRecorded([
                new PaidLeaveScheduleEntryCreated(self::USER, 'n18', '2026-04-01', 'normal', 11.0, true, 18),
            ]);
    }

    public function test_entries_without_nominal_months_are_identified_by_date_only(): void
    {
        // 古いイベント(名目の月数なし)の行は、候補に月数があっても日付だけで同じ行とみなす。
        PaidLeaveScheduleAggregate::fake(self::USER)->forUser(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated(self::USER, 'old', '2026-04-01', 'normal', 10.0),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->ensureFutureScheduleGenerated([
                    ['entryId' => 'n6', 'scheduledOn' => '2026-04-01', 'category' => 'normal', 'candidateGrantDays' => 10.0, 'nominalServiceMonths' => 6],
                ]);
                $aggregate->recalculateFutureSchedule([
                    ['entryId' => 'n6', 'scheduledOn' => '2026-04-01', 'category' => 'normal', 'candidateGrantDays' => 10.0, 'nominalServiceMonths' => 6],
                ], '再計算');
            })
            ->assertNothingRecorded();

        // 付与済みの古い行は、同じ日の月数ありの候補を消費する。
        PaidLeaveScheduleAggregate::fake(self::USER)->forUser(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated(self::USER, 'old', '2026-04-01', 'normal', 10.0),
                new PaidLeaveScheduleEntryGranted(self::USER, 'old', 'grant-1', 'admin-1'),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->recalculateFutureSchedule([
                    ['entryId' => 'n6', 'scheduledOn' => '2026-04-01', 'category' => 'normal', 'candidateGrantDays' => 10.0, 'nominalServiceMonths' => 6],
                ], '再計算');
            })
            ->assertNothingRecorded();
    }
}
