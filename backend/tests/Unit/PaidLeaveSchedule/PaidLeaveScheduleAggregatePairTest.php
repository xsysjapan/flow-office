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
 * まとめて付与する2件(同じ社員・同じ付与予定日で、名目の継続勤務月数が初回とその+12か月の2行)は常に組で扱う
 * (docs/changesets/20261021-paid-leave-fiscal-mass-grant-and-auto-assessment/spec.md 論点10)。
 * 判定の上書き・個別修正・置き換え(Superseded)・取消は、組の2行に同じ操作が及ぶ。
 */
class PaidLeaveScheduleAggregatePairTest extends TestCase
{
    private const USER = 'user-1';

    private const ELIGIBLE = PaidLeaveScheduleAggregate::STATUS_ELIGIBLE;

    /** 2026-04-01に名目6か月(10日)と18か月(11日)の組を作るイベント。 */
    private function pairCreated(): array
    {
        return [
            new PaidLeaveScheduleEntryCreated(self::USER, 'e6', '2026-04-01', 'normal', 10.0, true, 6),
            new PaidLeaveScheduleEntryCreated(self::USER, 'e18', '2026-04-01', 'normal', 11.0, true, 18),
        ];
    }

    /** 組の2行それぞれの判定(a6・a18)を記録するイベント。 */
    private function pairAssessed(string $result6 = self::ELIGIBLE, string $result18 = self::ELIGIBLE): array
    {
        return [
            ...$this->pairCreated(),
            new PaidLeaveScheduleAssessmentRecorded(self::USER, 'e6', 'a6', '2026-01-10', '2026-03-31', 55, 50, 0, 95.9, 'v2', $result6, 68),
            new PaidLeaveScheduleAssessmentRecorded(self::USER, 'e18', 'a18', '2026-04-01', '2027-07-09', 0, 0, 0, 100.0, 'v2', $result18),
        ];
    }

    // ---- 組の判定 ----

    public function test_two_rows_with_the_same_date_and_twelve_months_apart_are_a_pair(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)->forUser(self::USER)
            ->given($this->pairCreated())
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $this->assertSame('e18', $aggregate->pairedEntryId('e6'));
                $this->assertSame('e6', $aggregate->pairedEntryId('e18'));
            });
    }

    public function test_rows_on_different_dates_or_without_months_or_cancelled_are_not_a_pair(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)->forUser(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated(self::USER, 'a', '2026-04-01', 'normal', 10.0, true, 6),
                new PaidLeaveScheduleEntryCreated(self::USER, 'b', '2027-04-01', 'normal', 11.0, true, 18),
                new PaidLeaveScheduleEntryCreated(self::USER, 'c', '2026-10-01', 'normal', 10.0),
                new PaidLeaveScheduleEntryCreated(self::USER, 'd', '2026-10-01', 'normal', 10.0, true, 18),
                new PaidLeaveScheduleEntryCreated(self::USER, 'e', '2026-11-01', 'normal', 10.0, true, 6),
                new PaidLeaveScheduleEntryCreated(self::USER, 'f', '2026-11-01', 'normal', 11.0, true, 18),
                new PaidLeaveScheduleEntryCancelled(self::USER, 'f', '取消'),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $this->assertNull($aggregate->pairedEntryId('a'));
                $this->assertNull($aggregate->pairedEntryId('b'));
                $this->assertNull($aggregate->pairedEntryId('c'), '名目の月数がnullの行は組にしない');
                $this->assertNull($aggregate->pairedEntryId('d'));
                $this->assertNull($aggregate->pairedEntryId('e'), '相手が取消済みなら組にしない');
                $this->assertNull($aggregate->pairedEntryId('missing'));
            });
    }

    // ---- 上書き ----

    public function test_override_applies_to_both_rows_of_the_pair(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)->forUser(self::USER)
            ->given($this->pairAssessed(PaidLeaveScheduleAggregate::STATUS_NOT_ELIGIBLE))
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                // 片方(18か月側)を指定しても、2行に同じ上書きが記録される(名目の小さい順)。
                $aggregate->overrideScheduleAssessment('e18', self::ELIGIBLE, '育休からの復職', 'admin-1');
            })
            ->assertRecorded([
                new PaidLeaveScheduleAssessmentOverridden(self::USER, 'e6', 'a6', self::ELIGIBLE, '育休からの復職', 'admin-1'),
                new PaidLeaveScheduleAssessmentOverridden(self::USER, 'e18', 'a18', self::ELIGIBLE, '育休からの復職', 'admin-1'),
            ]);
    }

    public function test_override_records_nothing_when_the_partner_has_no_assessment(): void
    {
        $this->expectException(DomainRuleException::class);

        PaidLeaveScheduleAggregate::fake(self::USER)->forUser(self::USER)
            ->given([
                ...$this->pairCreated(),
                new PaidLeaveScheduleAssessmentRecorded(self::USER, 'e6', 'a6', '2026-01-10', '2026-03-31', 55, 50, 0, 95.9, 'v2', self::ELIGIBLE, 68),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->overrideScheduleAssessment('e6', PaidLeaveScheduleAggregate::STATUS_NOT_ELIGIBLE, '理由', 'admin-1');
            });
    }

    public function test_override_is_rejected_when_the_partner_is_already_granted(): void
    {
        $this->expectException(DomainRuleException::class);

        PaidLeaveScheduleAggregate::fake(self::USER)->forUser(self::USER)
            ->given([
                ...$this->pairAssessed(),
                new PaidLeaveScheduleEntryGranted(self::USER, 'e18', 'g18', 'admin-1'),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->overrideScheduleAssessment('e6', PaidLeaveScheduleAggregate::STATUS_NOT_ELIGIBLE, '理由', 'admin-1');
            });
    }

    // ---- 個別修正 ----

    public function test_manual_edit_applies_to_both_rows_of_the_pair(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)->forUser(self::USER)
            ->given($this->pairCreated())
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->manuallyEditScheduleEntry('e6', ['candidateGrantDays' => 12.0], '個別調整', 'admin-1');
            })
            ->assertRecorded([
                new PaidLeaveScheduleEntryManuallyEdited(self::USER, 'e6', ['candidateGrantDays' => 12.0], '個別調整', 'admin-1'),
                new PaidLeaveScheduleEntryManuallyEdited(self::USER, 'e18', ['candidateGrantDays' => 12.0], '個別調整', 'admin-1'),
            ]);
    }

    // ---- 取消 ----

    public function test_cancel_applies_to_both_rows_of_the_pair(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)->forUser(self::USER)
            ->given($this->pairCreated())
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->cancelEntry('e18', '退職確定');
            })
            ->assertRecorded([
                new PaidLeaveScheduleEntryCancelled(self::USER, 'e6', '退職確定'),
                new PaidLeaveScheduleEntryCancelled(self::USER, 'e18', '退職確定'),
            ]);
    }

    public function test_cancel_is_rejected_when_the_partner_is_already_granted(): void
    {
        $this->expectException(DomainRuleException::class);

        PaidLeaveScheduleAggregate::fake(self::USER)->forUser(self::USER)
            ->given([
                ...$this->pairAssessed(),
                new PaidLeaveScheduleEntryGranted(self::USER, 'e6', 'g6', 'admin-1'),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->cancelEntry('e18', '退職確定');
            });
    }

    // ---- 再計算(置き換え・取消) ----

    public function test_recalculation_supersedes_both_rows_when_only_one_changes(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)->forUser(self::USER)
            ->given($this->pairCreated())
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                // 名目18か月の日数だけが11日→12日に変わる。変わらない6か月側も組として置き換える。
                $aggregate->recalculateFutureSchedule([
                    ['entryId' => 'n6', 'scheduledOn' => '2026-04-01', 'category' => 'normal', 'candidateGrantDays' => 10.0, 'nominalServiceMonths' => 6],
                    ['entryId' => 'n18', 'scheduledOn' => '2026-04-01', 'category' => 'normal', 'candidateGrantDays' => 12.0, 'nominalServiceMonths' => 18],
                ], '法定の変更');
            })
            ->assertRecorded([
                new PaidLeaveScheduleEntrySuperseded(self::USER, 'e6', '法定の変更', '2026-04-01', 'normal', 10.0, 6),
                new PaidLeaveScheduleEntryCreated(self::USER, 'n6', '2026-04-01', 'normal', 10.0, true, 6),
                new PaidLeaveScheduleEntrySuperseded(self::USER, 'e18', '法定の変更', '2026-04-01', 'normal', 11.0, 18),
                new PaidLeaveScheduleEntryCreated(self::USER, 'n18', '2026-04-01', 'normal', 12.0, true, 18),
            ]);
    }

    public function test_recalculation_cancels_both_rows_when_the_pair_is_no_longer_needed(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)->forUser(self::USER)
            ->given($this->pairCreated())
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->recalculateFutureSchedule([], '利用開始日の変更');
            })
            ->assertRecorded([
                new PaidLeaveScheduleEntryCancelled(self::USER, 'e6', '利用開始日の変更'),
                new PaidLeaveScheduleEntryCancelled(self::USER, 'e18', '利用開始日の変更'),
            ]);
    }

    public function test_recalculation_cancels_the_partner_and_recreates_its_candidate_when_only_one_row_is_needed(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)->forUser(self::USER)
            ->given($this->pairCreated())
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                // 候補に18か月側だけが残る(6か月側は不要)。組は同じ扱いなので、18か月側も置き換えて作り直す。
                $aggregate->recalculateFutureSchedule([
                    ['entryId' => 'n18', 'scheduledOn' => '2026-04-01', 'category' => 'normal', 'candidateGrantDays' => 11.0, 'nominalServiceMonths' => 18],
                ], '再計算');
            })
            ->assertRecorded([
                new PaidLeaveScheduleEntryCancelled(self::USER, 'e6', '再計算'),
                new PaidLeaveScheduleEntrySuperseded(self::USER, 'e18', '再計算', '2026-04-01', 'normal', 11.0, 18),
                new PaidLeaveScheduleEntryCreated(self::USER, 'n18', '2026-04-01', 'normal', 11.0, true, 18),
            ]);
    }

    public function test_recalculation_is_a_noop_when_the_pair_is_unchanged(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)->forUser(self::USER)
            ->given($this->pairCreated())
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->recalculateFutureSchedule([
                    ['entryId' => 'n6', 'scheduledOn' => '2026-04-01', 'category' => 'normal', 'candidateGrantDays' => 10.0, 'nominalServiceMonths' => 6],
                    ['entryId' => 'n18', 'scheduledOn' => '2026-04-01', 'category' => 'normal', 'candidateGrantDays' => 11.0, 'nominalServiceMonths' => 18],
                ], '再計算');
            })
            ->assertNothingRecorded();
    }

    public function test_recalculation_leaves_a_non_pair_row_alone_when_another_row_changes(): void
    {
        PaidLeaveScheduleAggregate::fake(self::USER)->forUser(self::USER)
            ->given([
                new PaidLeaveScheduleEntryCreated(self::USER, 'e1', '2026-04-01', 'normal', 10.0, true, 6),
                new PaidLeaveScheduleEntryCreated(self::USER, 'e2', '2027-04-01', 'normal', 11.0, true, 18),
            ])
            ->when(function (PaidLeaveScheduleAggregate $aggregate) {
                $aggregate->recalculateFutureSchedule([
                    ['entryId' => 'n1', 'scheduledOn' => '2026-04-01', 'category' => 'normal', 'candidateGrantDays' => 10.0, 'nominalServiceMonths' => 6],
                    ['entryId' => 'n2', 'scheduledOn' => '2027-04-01', 'category' => 'normal', 'candidateGrantDays' => 12.0, 'nominalServiceMonths' => 18],
                ], '再計算');
            })
            ->assertRecorded([
                new PaidLeaveScheduleEntrySuperseded(self::USER, 'e2', '再計算', '2027-04-01', 'normal', 11.0, 18),
                new PaidLeaveScheduleEntryCreated(self::USER, 'n2', '2027-04-01', 'normal', 12.0, true, 18),
            ]);
    }
}
