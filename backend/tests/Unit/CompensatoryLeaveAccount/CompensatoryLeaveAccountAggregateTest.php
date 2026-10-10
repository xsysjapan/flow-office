<?php

namespace Tests\Unit\CompensatoryLeaveAccount;

use App\Domain\CompensatoryLeaveAccount\Aggregates\CompensatoryLeaveAccountAggregate;
use App\Domain\CompensatoryLeaveAccount\Events\CompensatoryLeaveAccountGrantCancelled;
use App\Domain\CompensatoryLeaveAccount\Events\CompensatoryLeaveAccountGrantConfirmed;
use App\Domain\CompensatoryLeaveAccount\Events\CompensatoryLeaveAccountGrantManuallyGranted;
use App\Domain\CompensatoryLeaveAccount\Events\CompensatoryLeaveAccountGrantRemoved;
use App\Domain\CompensatoryLeaveAccount\Events\CompensatoryLeaveAccountGrantSynced;
use App\Domain\CompensatoryLeaveAccount\Events\CompensatoryLeaveAccountMigrated;
use App\Domain\CompensatoryLeaveAccount\Events\CompensatoryLeaveAccountUsageCancelled;
use App\Domain\CompensatoryLeaveAccount\Events\CompensatoryLeaveAccountUsageConfirmed;
use App\Domain\CompensatoryLeaveAccount\Events\CompensatoryLeaveAccountUsageDesignated;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\SpecialLeaveAccount\Aggregates\SpecialLeaveAccountAggregate;
use Tests\TestCase;

/**
 * CompensatoryLeaveAccountAggregateの単体テスト。すべてEvent→Aggregate replay→Commandの結果を
 * 検証する形式で行い、Projection(Eloquent)は使わない。
 *
 * 移植元の現行ルール:
 * - SyncCompensatoryLeaveGrantHandler(同期・削除)
 * - ConfirmCompensatoryLeaveGrantsForMonthHandler(月次確定と有効期限)
 * - GrantCompensatoryLeaveHandler(手動付与)
 * - CancelCompensatoryLeaveGrantHandler(付与取消)
 * - ApproveCompensatoryLeaveRequestHandler::planConsumption(承認時の充当)
 * - CancelCompensatoryLeaveRequestHandler(承認済みの取消は充当を戻す)
 */
class CompensatoryLeaveAccountAggregateTest extends TestCase
{
    private const USER = 'user-1';

    private const ALL_EVENTS = [
        CompensatoryLeaveAccountGrantSynced::class,
        CompensatoryLeaveAccountGrantRemoved::class,
        CompensatoryLeaveAccountGrantConfirmed::class,
        CompensatoryLeaveAccountGrantManuallyGranted::class,
        CompensatoryLeaveAccountGrantCancelled::class,
        CompensatoryLeaveAccountUsageDesignated::class,
        CompensatoryLeaveAccountUsageConfirmed::class,
        CompensatoryLeaveAccountUsageCancelled::class,
        CompensatoryLeaveAccountMigrated::class,
    ];

    private const REMOVE_REASON = '休日出勤の実績が取り消されたため';

    // ---- 測定用のイベント生成ヘルパ ----

    /** 休日出勤日 2026-10-03 の日単位の手動付与(確定済み)。 */
    private function manualDailyGrant(string $grantId, float $days, ?string $expiresOn): CompensatoryLeaveAccountGrantManuallyGranted
    {
        return new CompensatoryLeaveAccountGrantManuallyGranted($grantId, '2026-10-03', $days, null, $expiresOn, null);
    }

    /** 休日出勤日 2026-10-03 の時間単位の手動付与(確定済み)。 */
    private function manualHourlyGrant(string $grantId, int $minutes, ?string $expiresOn): CompensatoryLeaveAccountGrantManuallyGranted
    {
        return new CompensatoryLeaveAccountGrantManuallyGranted($grantId, '2026-10-03', 0.0, $minutes, $expiresOn, null);
    }

    /** 利用日 2026-10-10 の消化記録(申請中)。 */
    private function designated(string $usageId, string $requestId, string $usageType, float $days, ?int $minutes): CompensatoryLeaveAccountUsageDesignated
    {
        return new CompensatoryLeaveAccountUsageDesignated($usageId, $requestId, '2026-10-10', $usageType, $days, $minutes);
    }

    private function allocDaily(string $grantId, float $days): array
    {
        return ['grantId' => $grantId, 'allocatedDays' => $days, 'allocatedMinutes' => 0];
    }

    private function allocHourly(string $grantId, int $minutes): array
    {
        return ['grantId' => $grantId, 'allocatedDays' => 0.0, 'allocatedMinutes' => $minutes];
    }

    // ---- 集約ID ----

    public function test_stream_id_is_derived_deterministically_from_user_id(): void
    {
        $id = CompensatoryLeaveAccountAggregate::streamIdFor('user-1');

        $this->assertSame($id, CompensatoryLeaveAccountAggregate::streamIdFor('user-1'));
        $this->assertNotSame($id, CompensatoryLeaveAccountAggregate::streamIdFor('user-2'));
        $this->assertNotSame('user-1', $id);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id);
    }

    public function test_stream_id_differs_from_special_leave_account_of_same_user(): void
    {
        $this->assertNotSame(
            SpecialLeaveAccountAggregate::streamIdFor(self::USER),
            CompensatoryLeaveAccountAggregate::streamIdFor(self::USER),
        );
    }

    // ---- 休日出勤からの付与の同期(SyncCompensatoryLeaveGrantHandler) ----

    public function test_holiday_work_creates_draft_grant(): void
    {
        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->syncGrantFromHolidayWork('g-new', '2026-10-04', true, true, 480, 'daily', null);
            })
            ->assertRecorded([
                new CompensatoryLeaveAccountGrantSynced('g-new', '2026-10-04', 1.0, null),
            ]);
    }

    public function test_holiday_work_updates_existing_draft_keeping_its_id(): void
    {
        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->given([
                new CompensatoryLeaveAccountGrantSynced('g1', '2026-10-04', 1.0, null),
            ])
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->syncGrantFromHolidayWork('g-unused', '2026-10-04', true, true, 240, 'hourly', null);
            })
            ->assertRecorded([
                new CompensatoryLeaveAccountGrantSynced('g1', '2026-10-04', 0.0, 240),
            ]);
    }

    public function test_non_holiday_removes_existing_draft(): void
    {
        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->given([
                new CompensatoryLeaveAccountGrantSynced('g1', '2026-10-04', 1.0, null),
            ])
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->syncGrantFromHolidayWork('g-x', '2026-10-04', true, false, 480, 'daily', null);
            })
            ->assertRecorded([
                new CompensatoryLeaveAccountGrantRemoved('g1', self::REMOVE_REASON),
            ]);
    }

    public function test_zero_minutes_removes_existing_draft(): void
    {
        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->given([
                new CompensatoryLeaveAccountGrantSynced('g1', '2026-10-04', 1.0, null),
            ])
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->syncGrantFromHolidayWork('g-x', '2026-10-04', true, true, 0, 'daily', null);
            })
            ->assertRecorded([
                new CompensatoryLeaveAccountGrantRemoved('g1', self::REMOVE_REASON),
            ]);
    }

    public function test_non_holiday_without_draft_records_nothing(): void
    {
        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->syncGrantFromHolidayWork('g-x', '2026-10-04', true, false, 480, 'daily', null);
            })
            ->assertNotRecorded(self::ALL_EVENTS);
    }

    public function test_disabled_setting_records_nothing_even_with_existing_draft(): void
    {
        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->given([
                new CompensatoryLeaveAccountGrantSynced('g1', '2026-10-04', 1.0, null),
            ])
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->syncGrantFromHolidayWork('g-x', '2026-10-04', false, false, 0, 'daily', null);
            })
            ->assertNotRecorded(self::ALL_EVENTS);
    }

    public function test_confirmed_grant_is_not_touched_by_sync(): void
    {
        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->given([
                new CompensatoryLeaveAccountGrantSynced('g1', '2026-10-04', 1.0, null),
                new CompensatoryLeaveAccountGrantConfirmed('g1', '2026-10-31 18:00:00', null),
            ])
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->syncGrantFromHolidayWork('g-new', '2026-10-04', true, true, 240, 'hourly', null);
            })
            ->assertNotRecorded(self::ALL_EVENTS);
    }

    public function test_confirmed_grant_is_not_removed_when_holiday_work_is_gone(): void
    {
        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->given([
                new CompensatoryLeaveAccountGrantSynced('g1', '2026-10-04', 1.0, null),
                new CompensatoryLeaveAccountGrantConfirmed('g1', '2026-10-31 18:00:00', null),
            ])
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->syncGrantFromHolidayWork('g-x', '2026-10-04', true, false, 0, 'daily', null);
            })
            ->assertNotRecorded(self::ALL_EVENTS);
    }

    public function test_cancelled_grant_is_not_recreated_by_sync(): void
    {
        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->given([
                new CompensatoryLeaveAccountGrantSynced('g1', '2026-10-04', 1.0, null),
                new CompensatoryLeaveAccountGrantConfirmed('g1', '2026-10-31 18:00:00', null),
                new CompensatoryLeaveAccountGrantCancelled('g1', 'admin-1', null),
            ])
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->syncGrantFromHolidayWork('g-new', '2026-10-04', true, true, 480, 'daily', null);
            })
            ->assertNotRecorded(self::ALL_EVENTS);
    }

    public function test_removed_draft_is_recreated_with_new_id_on_holiday_work(): void
    {
        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->given([
                new CompensatoryLeaveAccountGrantSynced('g1', '2026-10-04', 1.0, null),
                new CompensatoryLeaveAccountGrantRemoved('g1', self::REMOVE_REASON),
            ])
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->syncGrantFromHolidayWork('g2', '2026-10-04', true, true, 480, 'daily', null);
            })
            ->assertRecorded([
                new CompensatoryLeaveAccountGrantSynced('g2', '2026-10-04', 1.0, null),
            ]);
    }

    public function test_sync_for_another_work_date_creates_separate_grant(): void
    {
        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->given([
                new CompensatoryLeaveAccountGrantSynced('g1', '2026-10-04', 1.0, null),
            ])
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->syncGrantFromHolidayWork('g2', '2026-10-05', true, true, 480, 'daily', null);
            })
            ->assertRecorded([
                new CompensatoryLeaveAccountGrantSynced('g2', '2026-10-05', 1.0, null),
            ]);
    }

    public function test_half_day_unit_sync_uses_threshold(): void
    {
        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->syncGrantFromHolidayWork('g1', '2026-10-04', true, true, 240, 'half_day', 240);
            })
            ->assertRecorded([
                new CompensatoryLeaveAccountGrantSynced('g1', '2026-10-04', 0.5, null),
            ]);
    }

    public function test_new_grant_id_that_already_exists_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->given([
                new CompensatoryLeaveAccountGrantSynced('g1', '2026-10-04', 1.0, null),
            ])
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->syncGrantFromHolidayWork('g1', '2026-10-05', true, true, 480, 'daily', null);
            });
    }

    // ---- 月次提出での確定(ConfirmCompensatoryLeaveGrantsForMonthHandler) ----

    public function test_confirms_only_drafts_within_period_inclusive_of_both_ends(): void
    {
        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->given([
                new CompensatoryLeaveAccountGrantSynced('g-before', '2026-09-30', 1.0, null),
                new CompensatoryLeaveAccountGrantSynced('g-first', '2026-10-01', 1.0, null),
                new CompensatoryLeaveAccountGrantSynced('g-last', '2026-10-31', 1.0, null),
                new CompensatoryLeaveAccountGrantSynced('g-after', '2026-11-01', 1.0, null),
            ])
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->confirmGrantsForPeriod('2026-10-01', '2026-10-31', '2026-11-05 10:00:00', 30);
            })
            ->assertRecorded([
                new CompensatoryLeaveAccountGrantConfirmed('g-first', '2026-11-05 10:00:00', '2026-12-05'),
                new CompensatoryLeaveAccountGrantConfirmed('g-last', '2026-11-05 10:00:00', '2026-12-05'),
            ]);
    }

    public function test_confirmation_without_valid_days_is_unlimited(): void
    {
        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->given([
                new CompensatoryLeaveAccountGrantSynced('g1', '2026-10-03', 1.0, null),
            ])
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->confirmGrantsForPeriod('2026-10-01', '2026-10-31', '2026-11-05 10:00:00', null);
            })
            ->assertRecorded([
                new CompensatoryLeaveAccountGrantConfirmed('g1', '2026-11-05 10:00:00', null),
            ]);
    }

    public function test_zero_valid_days_expires_on_confirmation_date(): void
    {
        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->given([
                new CompensatoryLeaveAccountGrantSynced('g1', '2026-10-03', 1.0, null),
            ])
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->confirmGrantsForPeriod('2026-10-01', '2026-10-31', '2026-11-05 10:00:00', 0);
            })
            ->assertRecorded([
                new CompensatoryLeaveAccountGrantConfirmed('g1', '2026-11-05 10:00:00', '2026-11-05'),
            ]);
    }

    public function test_expiry_crosses_month_end_in_non_leap_year(): void
    {
        // 2027-01-31 + 30日 = 2027-03-02(2027年2月は28日)。
        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->given([
                new CompensatoryLeaveAccountGrantSynced('g1', '2027-01-20', 1.0, null),
            ])
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->confirmGrantsForPeriod('2027-01-01', '2027-01-31', '2027-01-31 09:00:00', 30);
            })
            ->assertRecorded([
                new CompensatoryLeaveAccountGrantConfirmed('g1', '2027-01-31 09:00:00', '2027-03-02'),
            ]);
    }

    public function test_period_end_on_leap_day_includes_that_day(): void
    {
        // 2028年は閏年。末日(2028-02-29)の休日出勤は当月の提出で確定する。
        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->given([
                new CompensatoryLeaveAccountGrantSynced('g-leap', '2028-02-29', 1.0, null),
                new CompensatoryLeaveAccountGrantSynced('g-mar', '2028-03-01', 1.0, null),
            ])
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->confirmGrantsForPeriod('2028-02-01', '2028-02-29', '2028-03-01 09:00:00', null);
            })
            ->assertRecorded([
                new CompensatoryLeaveAccountGrantConfirmed('g-leap', '2028-03-01 09:00:00', null),
            ]);
    }

    public function test_leap_day_expiry_is_computed_by_calendar(): void
    {
        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->given([
                new CompensatoryLeaveAccountGrantSynced('g1', '2028-02-28', 1.0, null),
            ])
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->confirmGrantsForPeriod('2028-02-01', '2028-02-29', '2028-02-28 10:00:00', 1);
            })
            ->assertRecorded([
                new CompensatoryLeaveAccountGrantConfirmed('g1', '2028-02-28 10:00:00', '2028-02-29'),
            ]);
    }

    public function test_no_draft_in_period_records_nothing(): void
    {
        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->given([
                new CompensatoryLeaveAccountGrantSynced('g-sep', '2026-09-30', 1.0, null),
            ])
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->confirmGrantsForPeriod('2026-10-01', '2026-10-31', '2026-11-05 10:00:00', 30);
            })
            ->assertNotRecorded(self::ALL_EVENTS);
    }

    public function test_already_confirmed_grant_is_not_confirmed_again(): void
    {
        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->given([
                new CompensatoryLeaveAccountGrantSynced('g1', '2026-10-03', 1.0, null),
                new CompensatoryLeaveAccountGrantConfirmed('g1', '2026-11-05 10:00:00', '2026-12-05'),
            ])
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->confirmGrantsForPeriod('2026-10-01', '2026-10-31', '2026-11-30 10:00:00', 30);
            })
            ->assertNotRecorded(self::ALL_EVENTS);
    }

    // ---- 手動付与(GrantCompensatoryLeaveHandler) ----

    public function test_manual_grant_for_holiday_work_is_confirmed_at_once(): void
    {
        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->grantManually('g-m', '2026-10-03', true, 480, 'daily', null, '2027-03-31', '休日出勤振替');
            })
            ->assertRecorded([
                new CompensatoryLeaveAccountGrantManuallyGranted('g-m', '2026-10-03', 1.0, null, '2027-03-31', '休日出勤振替'),
            ]);
    }

    public function test_manual_grant_without_expiry_and_reason(): void
    {
        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->grantManually('g-m', '2026-10-03', true, 480, 'daily', null, null, null);
            })
            ->assertRecorded([
                new CompensatoryLeaveAccountGrantManuallyGranted('g-m', '2026-10-03', 1.0, null, null, null),
            ]);
    }

    public function test_manual_hourly_grant_uses_minutes(): void
    {
        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->grantManually('g-m', '2026-10-03', true, 150, 'hourly', null, null, null);
            })
            ->assertRecorded([
                new CompensatoryLeaveAccountGrantManuallyGranted('g-m', '2026-10-03', 0.0, 150, null, null),
            ]);
    }

    public function test_manual_grant_on_non_holiday_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->grantManually('g-m', '2026-10-03', false, 480, 'daily', null, null, null);
            });
    }

    public function test_manual_grant_with_zero_minutes_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->grantManually('g-m', '2026-10-03', true, 0, 'daily', null, null, null);
            });
    }

    public function test_manual_grant_with_duplicate_id_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->given([
                $this->manualDailyGrant('g-m', 1.0, null),
            ])
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->grantManually('g-m', '2026-10-05', true, 480, 'daily', null, null, null);
            });
    }

    // ---- 付与取消(CancelCompensatoryLeaveGrantHandler) ----

    public function test_unused_confirmed_grant_can_be_cancelled(): void
    {
        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->given([
                $this->manualDailyGrant('g1', 1.0, null),
            ])
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->cancelGrant('g1', 'admin-1', '誤付与');
            })
            ->assertRecorded([
                new CompensatoryLeaveAccountGrantCancelled('g1', 'admin-1', '誤付与'),
            ]);
    }

    public function test_draft_grant_cannot_be_cancelled(): void
    {
        $this->expectException(DomainRuleException::class);

        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->given([
                new CompensatoryLeaveAccountGrantSynced('g1', '2026-10-03', 1.0, null),
            ])
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->cancelGrant('g1', 'admin-1', null);
            });
    }

    public function test_cancelled_grant_cannot_be_cancelled_again(): void
    {
        $this->expectException(DomainRuleException::class);

        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->given([
                $this->manualDailyGrant('g1', 1.0, null),
                new CompensatoryLeaveAccountGrantCancelled('g1', 'admin-1', null),
            ])
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->cancelGrant('g1', 'admin-1', null);
            });
    }

    public function test_grant_used_by_confirmed_usage_cannot_be_cancelled(): void
    {
        $this->expectException(DomainRuleException::class);

        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->given([
                $this->manualDailyGrant('g1', 1.0, null),
                $this->designated('u1', 'r1', 'full', 1.0, null),
                new CompensatoryLeaveAccountUsageConfirmed('u1', [$this->allocDaily('g1', 1.0)]),
            ])
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->cancelGrant('g1', 'admin-1', null);
            });
    }

    public function test_hourly_grant_used_by_minutes_cannot_be_cancelled(): void
    {
        $this->expectException(DomainRuleException::class);

        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->given([
                $this->manualHourlyGrant('g-h', 150, null),
                $this->designated('u1', 'r1', 'hourly', 0.0, 60),
                new CompensatoryLeaveAccountUsageConfirmed('u1', [$this->allocHourly('g-h', 60)]),
            ])
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->cancelGrant('g-h', 'admin-1', null);
            });
    }

    public function test_grant_is_cancellable_after_its_usage_is_cancelled(): void
    {
        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->given([
                $this->manualDailyGrant('g1', 1.0, null),
                $this->designated('u1', 'r1', 'full', 1.0, null),
                new CompensatoryLeaveAccountUsageConfirmed('u1', [$this->allocDaily('g1', 1.0)]),
            ])
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->cancelUsage('u1', null);
                $aggregate->cancelGrant('g1', 'admin-1', '誤付与');
            })
            ->assertRecorded([
                new CompensatoryLeaveAccountUsageCancelled('u1', [['grantId' => 'g1', 'releasedDays' => 1.0, 'releasedMinutes' => 0]], null),
                new CompensatoryLeaveAccountGrantCancelled('g1', 'admin-1', '誤付与'),
            ]);
    }

    public function test_cancelling_unknown_grant_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->cancelGrant('missing', 'admin-1', null);
            });
    }

    // ---- 消化記録の作成(申請時) ----

    public function test_usage_is_designated_without_allocation(): void
    {
        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->given([
                $this->manualDailyGrant('g1', 1.0, null),
            ])
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->designateUsage('u1', 'r1', '2026-10-10', 'full', 1.0, null);
            })
            ->assertRecorded([
                $this->designated('u1', 'r1', 'full', 1.0, null),
            ]);
    }

    public function test_designating_with_existing_usage_id_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->given([
                $this->designated('u1', 'r1', 'full', 1.0, null),
            ])
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->designateUsage('u1', 'r2', '2026-10-10', 'full', 1.0, null);
            });
    }

    public function test_second_active_usage_for_same_request_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->given([
                $this->designated('u1', 'r1', 'full', 1.0, null),
            ])
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->designateUsage('u2', 'r1', '2026-10-10', 'full', 1.0, null);
            });
    }

    public function test_resubmitted_request_gets_a_new_usage_after_cancellation(): void
    {
        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->given([
                $this->designated('u1', 'r1', 'full', 1.0, null),
                new CompensatoryLeaveAccountUsageCancelled('u1', [], '差戻し'),
            ])
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->designateUsage('u2', 'r1', '2026-10-10', 'full', 1.0, null);
            })
            ->assertRecorded([
                $this->designated('u2', 'r1', 'full', 1.0, null),
            ]);
    }

    public function test_hourly_usage_requires_minutes(): void
    {
        $this->expectException(DomainRuleException::class);

        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->designateUsage('u1', 'r1', '2026-10-10', 'hourly', 0.0, null);
            });
    }

    // ---- 承認時の充当(ApproveCompensatoryLeaveRequestHandler::planConsumption) ----

    public function test_confirm_allocates_from_nearest_expiry_first(): void
    {
        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->given([
                $this->manualDailyGrant('g-late', 1.0, '2027-06-30'),
                $this->manualDailyGrant('g-early', 1.0, '2026-12-31'),
                $this->designated('u1', 'r1', 'full', 1.0, null),
            ])
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->confirmUsage('u1');
            })
            ->assertRecorded([
                new CompensatoryLeaveAccountUsageConfirmed('u1', [$this->allocDaily('g-early', 1.0)]),
            ]);
    }

    public function test_grant_without_expiry_is_used_last(): void
    {
        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->given([
                $this->manualDailyGrant('g-none', 5.0, null),
                $this->manualDailyGrant('g-dated', 1.0, '2027-03-31'),
                $this->designated('u1', 'r1', 'full', 2.0, null),
            ])
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->confirmUsage('u1');
            })
            ->assertRecorded([
                new CompensatoryLeaveAccountUsageConfirmed('u1', [
                    $this->allocDaily('g-dated', 1.0),
                    $this->allocDaily('g-none', 1.0),
                ]),
            ]);
    }

    public function test_grants_with_same_expiry_are_used_in_registration_order(): void
    {
        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->given([
                $this->manualDailyGrant('g-first', 1.0, '2026-12-31'),
                $this->manualDailyGrant('g-second', 3.0, '2026-12-31'),
                $this->designated('u1', 'r1', 'full', 2.0, null),
            ])
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->confirmUsage('u1');
            })
            ->assertRecorded([
                new CompensatoryLeaveAccountUsageConfirmed('u1', [
                    $this->allocDaily('g-first', 1.0),
                    $this->allocDaily('g-second', 1.0),
                ]),
            ]);
    }

    public function test_expired_grant_is_not_used(): void
    {
        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->given([
                $this->manualDailyGrant('g-old', 3.0, '2026-10-09'),
                $this->manualDailyGrant('g-ok', 3.0, '2026-12-31'),
                $this->designated('u1', 'r1', 'full', 1.0, null),
            ])
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->confirmUsage('u1');
            })
            ->assertRecorded([
                new CompensatoryLeaveAccountUsageConfirmed('u1', [$this->allocDaily('g-ok', 1.0)]),
            ]);
    }

    public function test_grant_expiring_on_usage_day_is_still_used(): void
    {
        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->given([
                $this->manualDailyGrant('g-edge', 1.0, '2026-10-10'),
                $this->designated('u1', 'r1', 'full', 1.0, null),
            ])
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->confirmUsage('u1');
            })
            ->assertRecorded([
                new CompensatoryLeaveAccountUsageConfirmed('u1', [$this->allocDaily('g-edge', 1.0)]),
            ]);
    }

    public function test_usage_is_split_across_grants(): void
    {
        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->given([
                $this->manualDailyGrant('g1', 0.5, '2026-12-31'),
                $this->manualDailyGrant('g2', 1.0, '2027-03-31'),
                $this->designated('u1', 'r1', 'full', 1.0, null),
            ])
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->confirmUsage('u1');
            })
            ->assertRecorded([
                new CompensatoryLeaveAccountUsageConfirmed('u1', [
                    $this->allocDaily('g1', 0.5),
                    $this->allocDaily('g2', 0.5),
                ]),
            ]);
    }

    public function test_exactly_remaining_balance_confirms(): void
    {
        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->given([
                $this->manualDailyGrant('g1', 1.0, null),
                $this->designated('u1', 'r1', 'full', 1.0, null),
            ])
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->confirmUsage('u1');
            })
            ->assertRecorded([
                new CompensatoryLeaveAccountUsageConfirmed('u1', [$this->allocDaily('g1', 1.0)]),
            ]);
    }

    public function test_confirm_is_rejected_when_balance_is_short(): void
    {
        $this->expectException(DomainRuleException::class);

        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->given([
                $this->manualDailyGrant('g1', 1.0, null),
                $this->designated('u1', 'r1', 'full', 1.5, null),
            ])
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->confirmUsage('u1');
            });
    }

    public function test_confirm_is_rejected_when_balance_is_already_allocated(): void
    {
        $this->expectException(DomainRuleException::class);

        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->given([
                $this->manualDailyGrant('g1', 1.0, null),
                $this->designated('u0', 'r0', 'full', 1.0, null),
                new CompensatoryLeaveAccountUsageConfirmed('u0', [$this->allocDaily('g1', 1.0)]),
                $this->designated('u1', 'r1', 'half_am', 0.5, null),
            ])
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->confirmUsage('u1');
            });
    }

    public function test_draft_grant_is_not_used(): void
    {
        $this->expectException(DomainRuleException::class);

        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->given([
                new CompensatoryLeaveAccountGrantSynced('g1', '2026-10-03', 1.0, null),
                $this->designated('u1', 'r1', 'full', 1.0, null),
            ])
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->confirmUsage('u1');
            });
    }

    public function test_cancelled_grant_is_not_used(): void
    {
        $this->expectException(DomainRuleException::class);

        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->given([
                $this->manualDailyGrant('g1', 1.0, null),
                new CompensatoryLeaveAccountGrantCancelled('g1', 'admin-1', null),
                $this->designated('u1', 'r1', 'full', 1.0, null),
            ])
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->confirmUsage('u1');
            });
    }

    public function test_hourly_usage_uses_only_hourly_grants(): void
    {
        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->given([
                $this->manualDailyGrant('g-daily', 1.0, null),
                $this->manualHourlyGrant('g-hourly', 240, null),
                $this->designated('u1', 'r1', 'hourly', 0.0, 60),
            ])
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->confirmUsage('u1');
            })
            ->assertRecorded([
                new CompensatoryLeaveAccountUsageConfirmed('u1', [$this->allocHourly('g-hourly', 60)]),
            ]);
    }

    public function test_hourly_shortage_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->given([
                $this->manualHourlyGrant('g-hourly', 60, null),
                $this->designated('u1', 'r1', 'hourly', 0.0, 90),
            ])
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->confirmUsage('u1');
            });
    }

    public function test_daily_usage_does_not_use_hourly_grant(): void
    {
        $this->expectException(DomainRuleException::class);

        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->given([
                $this->manualHourlyGrant('g-hourly', 240, null),
                $this->designated('u1', 'r1', 'full', 1.0, null),
            ])
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->confirmUsage('u1');
            });
    }

    public function test_confirming_twice_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->given([
                $this->manualDailyGrant('g1', 1.0, null),
                $this->designated('u1', 'r1', 'full', 1.0, null),
                new CompensatoryLeaveAccountUsageConfirmed('u1', [$this->allocDaily('g1', 1.0)]),
            ])
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->confirmUsage('u1');
            });
    }

    public function test_confirming_cancelled_usage_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->given([
                $this->manualDailyGrant('g1', 1.0, null),
                $this->designated('u1', 'r1', 'full', 1.0, null),
                new CompensatoryLeaveAccountUsageCancelled('u1', [], null),
            ])
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->confirmUsage('u1');
            });
    }

    public function test_confirming_unknown_usage_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->confirmUsage('missing');
            });
    }

    // ---- 消化記録の取消(差戻し・取消) ----

    public function test_cancelling_designated_usage_releases_nothing(): void
    {
        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->given([
                $this->manualDailyGrant('g1', 1.0, null),
                $this->designated('u1', 'r1', 'full', 1.0, null),
            ])
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->cancelUsage('u1', '差戻し');
            })
            ->assertRecorded([
                new CompensatoryLeaveAccountUsageCancelled('u1', [], '差戻し'),
            ]);
    }

    public function test_cancelling_confirmed_usage_releases_allocations_and_restores_balance(): void
    {
        $remaining = null;

        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->given([
                $this->manualDailyGrant('g1', 1.0, null),
                $this->designated('u1', 'r1', 'full', 1.0, null),
                new CompensatoryLeaveAccountUsageConfirmed('u1', [$this->allocDaily('g1', 1.0)]),
            ])
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) use (&$remaining) {
                $aggregate->cancelUsage('u1', null);
                $remaining = $aggregate->remainingDays('2026-10-10');
            })
            ->assertRecorded([
                new CompensatoryLeaveAccountUsageCancelled('u1', [['grantId' => 'g1', 'releasedDays' => 1.0, 'releasedMinutes' => 0]], null),
            ]);

        $this->assertSame(1.0, $remaining);
    }

    public function test_cancelling_confirmed_hourly_usage_releases_minutes(): void
    {
        $remaining = null;

        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->given([
                $this->manualHourlyGrant('g-h', 240, null),
                $this->designated('u1', 'r1', 'hourly', 0.0, 90),
                new CompensatoryLeaveAccountUsageConfirmed('u1', [$this->allocHourly('g-h', 90)]),
            ])
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) use (&$remaining) {
                $aggregate->cancelUsage('u1', null);
                $remaining = $aggregate->remainingMinutes('2026-10-10');
            })
            ->assertRecorded([
                new CompensatoryLeaveAccountUsageCancelled('u1', [['grantId' => 'g-h', 'releasedDays' => 0.0, 'releasedMinutes' => 90]], null),
            ]);

        $this->assertSame(240, $remaining);
    }

    public function test_cancelling_twice_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->given([
                $this->designated('u1', 'r1', 'full', 1.0, null),
                new CompensatoryLeaveAccountUsageCancelled('u1', [], null),
            ])
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->cancelUsage('u1', null);
            });
    }

    public function test_cancelling_unknown_usage_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->cancelUsage('missing', null);
            });
    }

    // ---- 問い合わせ ----

    public function test_remaining_days_counts_only_confirmed_usable_daily_grants(): void
    {
        $remaining = [];

        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->given([
                $this->manualDailyGrant('g-expired', 4.0, '2026-10-09'),
                $this->manualDailyGrant('g-edge', 1.0, '2026-10-10'),
                $this->manualDailyGrant('g-none', 2.0, null),
                new CompensatoryLeaveAccountGrantSynced('g-draft', '2026-10-03', 1.0, null),
                $this->manualDailyGrant('g-cancelled', 3.0, null),
                new CompensatoryLeaveAccountGrantCancelled('g-cancelled', 'admin-1', null),
                $this->manualHourlyGrant('g-hourly', 240, null),
                $this->designated('u1', 'r1', 'full', 0.5, null),
                new CompensatoryLeaveAccountUsageConfirmed('u1', [$this->allocDaily('g-none', 0.5)]),
            ])
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) use (&$remaining) {
                $remaining['on_expiry_day'] = $aggregate->remainingDays('2026-10-10');
                $remaining['after_expiry_day'] = $aggregate->remainingDays('2026-10-11');
                $remaining['minutes'] = $aggregate->remainingMinutes('2026-10-10');
            });

        // 失効日当日は有効(g-edge 1.0 + g-none 2.0 - 0.5消化 = 2.5)。失効済み・取消済み・下書き・時間単位は含めない。
        $this->assertSame(2.5, $remaining['on_expiry_day']);
        // 翌日は g-edge が失効するため g-none の残り 1.5 のみ。
        $this->assertSame(1.5, $remaining['after_expiry_day']);
        $this->assertSame(240, $remaining['minutes']);
    }

    public function test_remaining_minutes_after_hourly_usage(): void
    {
        $remaining = null;

        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->given([
                $this->manualHourlyGrant('g-h', 150, null),
                $this->designated('u1', 'r1', 'hourly', 0.0, 90),
                new CompensatoryLeaveAccountUsageConfirmed('u1', [$this->allocHourly('g-h', 90)]),
            ])
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) use (&$remaining) {
                $remaining = [
                    'minutes' => $aggregate->remainingMinutes('2026-10-10'),
                    'days' => $aggregate->remainingDays('2026-10-10'),
                ];
            });

        $this->assertSame(60, $remaining['minutes']);
        $this->assertSame(0.0, $remaining['days']);
    }

    public function test_usage_and_grant_queries_reflect_state(): void
    {
        $results = [];

        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->given([
                $this->manualDailyGrant('g1', 1.0, null),
                $this->designated('u1', 'r1', 'full', 1.0, null),
            ])
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) use (&$results) {
                $results = [
                    'request_r1' => $aggregate->usageIdForRequest('r1'),
                    'request_r9' => $aggregate->usageIdForRequest('r9'),
                    'has_u1' => $aggregate->hasUsage('u1'),
                    'has_u9' => $aggregate->hasUsage('u9'),
                    'status_u1' => $aggregate->usageStatus('u1'),
                    'status_u9' => $aggregate->usageStatus('u9'),
                    'grant_g1' => $aggregate->grantStatus('g1'),
                    'grant_g9' => $aggregate->grantStatus('g9'),
                ];

                $aggregate->cancelUsage('u1', null);
                $results['request_r1_after_cancel'] = $aggregate->usageIdForRequest('r1');
                $results['status_u1_after_cancel'] = $aggregate->usageStatus('u1');
            });

        $this->assertSame('u1', $results['request_r1']);
        $this->assertNull($results['request_r9']);
        $this->assertTrue($results['has_u1']);
        $this->assertFalse($results['has_u9']);
        $this->assertSame('designated', $results['status_u1']);
        $this->assertNull($results['status_u9']);
        $this->assertSame('confirmed', $results['grant_g1']);
        $this->assertNull($results['grant_g9']);
        // 取消済みの消化記録も申請からは引けるまま(最後に作成された消化記録)。
        $this->assertSame('u1', $results['request_r1_after_cancel']);
        $this->assertSame('cancelled', $results['status_u1_after_cancel']);
    }

    // ---- 引き継ぎ(migrate) ----

    public function test_migrate_restores_grants_usages_and_allocations(): void
    {
        $results = [];

        $grants = [
            ['grantId' => 'g1', 'source' => 'manual', 'sourceWorkDate' => '2026-10-03', 'grantedDays' => 3.0, 'grantedMinutes' => null, 'status' => 'confirmed', 'expiresOn' => '2026-12-31'],
            ['grantId' => 'g-rev', 'source' => 'manual', 'sourceWorkDate' => '2026-10-03', 'grantedDays' => 1.0, 'grantedMinutes' => null, 'status' => 'cancelled', 'expiresOn' => null],
            ['grantId' => 'g-draft', 'source' => 'sync', 'sourceWorkDate' => '2026-10-04', 'grantedDays' => 1.0, 'grantedMinutes' => null, 'status' => 'draft', 'expiresOn' => null],
        ];
        $usages = [
            ['usageId' => 'u1', 'requestId' => 'r1', 'usedOn' => '2026-10-10', 'usageType' => 'full', 'usedDays' => 1.0, 'usedMinutes' => null, 'status' => 'confirmed', 'allocations' => [['grantId' => 'g1', 'allocatedDays' => 1.0, 'allocatedMinutes' => 0]]],
            ['usageId' => 'u2', 'requestId' => 'r2', 'usedOn' => '2026-10-11', 'usageType' => 'full', 'usedDays' => 0.5, 'usedMinutes' => null, 'status' => 'designated', 'allocations' => []],
        ];

        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) use ($grants, $usages, &$results) {
                $aggregate->migrate($grants, $usages);

                $results = [
                    'remaining' => $aggregate->remainingDays('2026-10-10'),
                    'status_u1' => $aggregate->usageStatus('u1'),
                    'status_u2' => $aggregate->usageStatus('u2'),
                    'request_r2' => $aggregate->usageIdForRequest('r2'),
                    'grant_draft' => $aggregate->grantStatus('g-draft'),
                ];

                $aggregate->cancelUsage('u1', null);
                $results['remaining_after_cancel'] = $aggregate->remainingDays('2026-10-10');
            })
            ->assertRecorded([
                new CompensatoryLeaveAccountMigrated($grants, $usages),
                new CompensatoryLeaveAccountUsageCancelled('u1', [['grantId' => 'g1', 'releasedDays' => 1.0, 'releasedMinutes' => 0]], null),
            ]);

        // 有効な付与は g1(3.0 - 1.0 = 2.0)のみ。取消済み・下書きは含めない。
        $this->assertSame(2.0, $results['remaining']);
        $this->assertSame('confirmed', $results['status_u1']);
        $this->assertSame('designated', $results['status_u2']);
        $this->assertSame('u2', $results['request_r2']);
        $this->assertSame('draft', $results['grant_draft']);
        $this->assertSame(3.0, $results['remaining_after_cancel']);
    }

    public function test_migrated_draft_grant_can_be_confirmed(): void
    {
        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->migrate([
                    ['grantId' => 'g-d', 'source' => 'sync', 'sourceWorkDate' => '2026-10-03', 'grantedDays' => 1.0, 'grantedMinutes' => null, 'status' => 'draft', 'expiresOn' => null],
                ], []);
                $aggregate->confirmGrantsForPeriod('2026-10-01', '2026-10-31', '2026-11-05 10:00:00', null);
            })
            ->assertRecorded([
                new CompensatoryLeaveAccountMigrated([
                    ['grantId' => 'g-d', 'source' => 'sync', 'sourceWorkDate' => '2026-10-03', 'grantedDays' => 1.0, 'grantedMinutes' => null, 'status' => 'draft', 'expiresOn' => null],
                ], []),
                new CompensatoryLeaveAccountGrantConfirmed('g-d', '2026-11-05 10:00:00', null),
            ]);
    }

    public function test_migrate_keeps_historical_shortage_as_recorded(): void
    {
        $remaining = null;

        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) use (&$remaining) {
                $aggregate->migrate(
                    [['grantId' => 'g1', 'source' => 'manual', 'sourceWorkDate' => '2026-10-03', 'grantedDays' => 2.0, 'grantedMinutes' => null, 'status' => 'confirmed', 'expiresOn' => null]],
                    [['usageId' => 'u1', 'requestId' => 'r1', 'usedOn' => '2026-10-10', 'usageType' => 'full', 'usedDays' => 2.0, 'usedMinutes' => null, 'status' => 'confirmed', 'allocations' => [['grantId' => 'g1', 'allocatedDays' => 1.0, 'allocatedMinutes' => 0]]]],
                );
                $remaining = $aggregate->remainingDays('2026-10-10');
            });

        $this->assertSame(1.0, $remaining);
    }

    public function test_migrate_is_rejected_when_account_is_not_empty(): void
    {
        $this->expectException(DomainRuleException::class);

        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->given([
                $this->manualDailyGrant('g0', 1.0, null),
            ])
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->migrate([], []);
            });
    }

    public function test_second_migrate_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->given([
                new CompensatoryLeaveAccountMigrated([], []),
            ])
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->migrate([], []);
            });
    }

    public function test_migrate_rejects_duplicate_grant_id(): void
    {
        $this->expectException(DomainRuleException::class);

        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->migrate([
                    ['grantId' => 'g1', 'source' => 'manual', 'sourceWorkDate' => '2026-10-03', 'grantedDays' => 1.0, 'grantedMinutes' => null, 'status' => 'confirmed', 'expiresOn' => null],
                    ['grantId' => 'g1', 'source' => 'manual', 'sourceWorkDate' => '2026-10-03', 'grantedDays' => 1.0, 'grantedMinutes' => null, 'status' => 'confirmed', 'expiresOn' => null],
                ], []);
            });
    }

    public function test_migrate_rejects_allocation_to_unknown_grant(): void
    {
        $this->expectException(DomainRuleException::class);

        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->migrate([], [
                    ['usageId' => 'u1', 'requestId' => 'r1', 'usedOn' => '2026-10-10', 'usageType' => 'full', 'usedDays' => 1.0, 'usedMinutes' => null, 'status' => 'confirmed', 'allocations' => [['grantId' => 'missing', 'allocatedDays' => 1.0, 'allocatedMinutes' => 0]]],
                ]);
            });
    }

    public function test_migrate_rejects_allocation_to_draft_grant(): void
    {
        $this->expectException(DomainRuleException::class);

        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->migrate(
                    [['grantId' => 'g-d', 'source' => 'sync', 'sourceWorkDate' => '2026-10-03', 'grantedDays' => 1.0, 'grantedMinutes' => null, 'status' => 'draft', 'expiresOn' => null]],
                    [['usageId' => 'u1', 'requestId' => 'r1', 'usedOn' => '2026-10-10', 'usageType' => 'full', 'usedDays' => 1.0, 'usedMinutes' => null, 'status' => 'confirmed', 'allocations' => [['grantId' => 'g-d', 'allocatedDays' => 1.0, 'allocatedMinutes' => 0]]]],
                );
            });
    }

    public function test_migrate_rejects_allocation_to_cancelled_grant(): void
    {
        $this->expectException(DomainRuleException::class);

        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->migrate(
                    [['grantId' => 'g-c', 'source' => 'manual', 'sourceWorkDate' => '2026-10-03', 'grantedDays' => 1.0, 'grantedMinutes' => null, 'status' => 'cancelled', 'expiresOn' => null]],
                    [['usageId' => 'u1', 'requestId' => 'r1', 'usedOn' => '2026-10-10', 'usageType' => 'full', 'usedDays' => 1.0, 'usedMinutes' => null, 'status' => 'confirmed', 'allocations' => [['grantId' => 'g-c', 'allocatedDays' => 1.0, 'allocatedMinutes' => 0]]]],
                );
            });
    }

    public function test_migrate_rejects_over_allocation_in_days(): void
    {
        $this->expectException(DomainRuleException::class);

        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->migrate(
                    [['grantId' => 'g1', 'source' => 'manual', 'sourceWorkDate' => '2026-10-03', 'grantedDays' => 1.0, 'grantedMinutes' => null, 'status' => 'confirmed', 'expiresOn' => null]],
                    [
                        ['usageId' => 'u1', 'requestId' => 'r1', 'usedOn' => '2026-10-10', 'usageType' => 'full', 'usedDays' => 1.0, 'usedMinutes' => null, 'status' => 'confirmed', 'allocations' => [['grantId' => 'g1', 'allocatedDays' => 1.0, 'allocatedMinutes' => 0]]],
                        ['usageId' => 'u2', 'requestId' => 'r2', 'usedOn' => '2026-10-11', 'usageType' => 'full', 'usedDays' => 0.5, 'usedMinutes' => null, 'status' => 'confirmed', 'allocations' => [['grantId' => 'g1', 'allocatedDays' => 0.5, 'allocatedMinutes' => 0]]],
                    ],
                );
            });
    }

    public function test_migrate_rejects_over_allocation_in_minutes(): void
    {
        $this->expectException(DomainRuleException::class);

        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->migrate(
                    [['grantId' => 'g-h', 'source' => 'manual', 'sourceWorkDate' => '2026-10-03', 'grantedDays' => 0.0, 'grantedMinutes' => 240, 'status' => 'confirmed', 'expiresOn' => null]],
                    [
                        ['usageId' => 'u1', 'requestId' => 'r1', 'usedOn' => '2026-10-10', 'usageType' => 'hourly', 'usedDays' => 0.0, 'usedMinutes' => 200, 'status' => 'confirmed', 'allocations' => [['grantId' => 'g-h', 'allocatedDays' => 0.0, 'allocatedMinutes' => 200]]],
                        ['usageId' => 'u2', 'requestId' => 'r2', 'usedOn' => '2026-10-11', 'usageType' => 'hourly', 'usedDays' => 0.0, 'usedMinutes' => 100, 'status' => 'confirmed', 'allocations' => [['grantId' => 'g-h', 'allocatedDays' => 0.0, 'allocatedMinutes' => 100]]],
                    ],
                );
            });
    }

    public function test_migrate_rejects_minutes_allocation_to_daily_grant(): void
    {
        $this->expectException(DomainRuleException::class);

        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->migrate(
                    [['grantId' => 'g1', 'source' => 'manual', 'sourceWorkDate' => '2026-10-03', 'grantedDays' => 1.0, 'grantedMinutes' => null, 'status' => 'confirmed', 'expiresOn' => null]],
                    [['usageId' => 'u1', 'requestId' => 'r1', 'usedOn' => '2026-10-10', 'usageType' => 'hourly', 'usedDays' => 0.0, 'usedMinutes' => 60, 'status' => 'confirmed', 'allocations' => [['grantId' => 'g1', 'allocatedDays' => 0.0, 'allocatedMinutes' => 60]]]],
                );
            });
    }

    public function test_migrate_rejects_duplicate_request_id(): void
    {
        $this->expectException(DomainRuleException::class);

        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->migrate([], [
                    ['usageId' => 'u1', 'requestId' => 'r1', 'usedOn' => '2026-10-10', 'usageType' => 'full', 'usedDays' => 1.0, 'usedMinutes' => null, 'status' => 'designated', 'allocations' => []],
                    ['usageId' => 'u2', 'requestId' => 'r1', 'usedOn' => '2026-10-11', 'usageType' => 'full', 'usedDays' => 1.0, 'usedMinutes' => null, 'status' => 'designated', 'allocations' => []],
                ]);
            });
    }

    public function test_migrate_rejects_duplicate_usage_id(): void
    {
        $this->expectException(DomainRuleException::class);

        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->migrate([], [
                    ['usageId' => 'u1', 'requestId' => 'r1', 'usedOn' => '2026-10-10', 'usageType' => 'full', 'usedDays' => 1.0, 'usedMinutes' => null, 'status' => 'designated', 'allocations' => []],
                    ['usageId' => 'u1', 'requestId' => 'r2', 'usedOn' => '2026-10-11', 'usageType' => 'full', 'usedDays' => 1.0, 'usedMinutes' => null, 'status' => 'designated', 'allocations' => []],
                ]);
            });
    }

    public function test_migrate_rejects_designated_usage_with_allocation(): void
    {
        $this->expectException(DomainRuleException::class);

        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->migrate(
                    [['grantId' => 'g1', 'source' => 'manual', 'sourceWorkDate' => '2026-10-03', 'grantedDays' => 1.0, 'grantedMinutes' => null, 'status' => 'confirmed', 'expiresOn' => null]],
                    [['usageId' => 'u1', 'requestId' => 'r1', 'usedOn' => '2026-10-10', 'usageType' => 'full', 'usedDays' => 1.0, 'usedMinutes' => null, 'status' => 'designated', 'allocations' => [['grantId' => 'g1', 'allocatedDays' => 1.0, 'allocatedMinutes' => 0]]]],
                );
            });
    }

    public function test_migrate_rejects_cancelled_usage_status(): void
    {
        $this->expectException(DomainRuleException::class);

        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->migrate([], [
                    ['usageId' => 'u1', 'requestId' => 'r1', 'usedOn' => '2026-10-10', 'usageType' => 'full', 'usedDays' => 1.0, 'usedMinutes' => null, 'status' => 'cancelled', 'allocations' => []],
                ]);
            });
    }

    public function test_migrate_rejects_hourly_usage_without_minutes(): void
    {
        $this->expectException(DomainRuleException::class);

        CompensatoryLeaveAccountAggregate::fake(self::USER)
            ->when(function (CompensatoryLeaveAccountAggregate $aggregate) {
                $aggregate->migrate([], [
                    ['usageId' => 'u1', 'requestId' => 'r1', 'usedOn' => '2026-10-10', 'usageType' => 'hourly', 'usedDays' => 0.0, 'usedMinutes' => null, 'status' => 'designated', 'allocations' => []],
                ]);
            });
    }
}
