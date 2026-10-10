<?php

namespace Tests\Unit\SpecialLeaveAccount;

use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\SpecialLeaveAccount\Aggregates\SpecialLeaveAccountAggregate;
use App\Domain\SpecialLeaveAccount\Events\SpecialLeaveAccountGrantRegistered;
use App\Domain\SpecialLeaveAccount\Events\SpecialLeaveAccountGrantRevoked;
use App\Domain\SpecialLeaveAccount\Events\SpecialLeaveAccountMigrated;
use App\Domain\SpecialLeaveAccount\Events\SpecialLeaveAccountUsageCancelled;
use App\Domain\SpecialLeaveAccount\Events\SpecialLeaveAccountUsageConfirmed;
use App\Domain\SpecialLeaveAccount\Events\SpecialLeaveAccountUsageDesignated;
use Tests\TestCase;

/**
 * SpecialLeaveAccountAggregateの単体テスト。すべてEvent→Aggregate replay→Commandの結果を
 * 検証する形式で行い、Projection(Eloquent)は使わない。
 * 移植元の現行ルール: ApproveSpecialLeaveRequestHandler::planConsumption /
 * RevokeSpecialLeaveGrantHandler / CancelSpecialLeaveRequestHandler。
 */
class SpecialLeaveAccountAggregateTest extends TestCase
{
    private const USER = 'user-1';

    private const TYPE = 1;

    private const OTHER_TYPE = 2;

    // ---- 集約ID ----

    public function test_stream_id_is_derived_deterministically_from_user_id(): void
    {
        $id = SpecialLeaveAccountAggregate::streamIdFor('user-1');

        $this->assertSame($id, SpecialLeaveAccountAggregate::streamIdFor('user-1'));
        $this->assertNotSame($id, SpecialLeaveAccountAggregate::streamIdFor('user-2'));
        $this->assertNotSame('user-1', $id);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id);
    }

    // ---- 付与・付与取消 ----

    public function test_grant_is_registered(): void
    {
        SpecialLeaveAccountAggregate::fake(self::USER)
            ->when(function (SpecialLeaveAccountAggregate $aggregate) {
                $aggregate->registerGrant('g1', self::TYPE, '2026-04-01', '2027-03-31', 3.0, '付与');
            })
            ->assertRecorded([
                new SpecialLeaveAccountGrantRegistered('g1', self::TYPE, '2026-04-01', '2027-03-31', 3.0, '付与'),
            ]);
    }

    public function test_grant_without_expiry_is_registered(): void
    {
        SpecialLeaveAccountAggregate::fake(self::USER)
            ->when(function (SpecialLeaveAccountAggregate $aggregate) {
                $aggregate->registerGrant('g1', self::TYPE, '2026-04-01', null, 3.0, null);
            })
            ->assertRecorded([
                new SpecialLeaveAccountGrantRegistered('g1', self::TYPE, '2026-04-01', null, 3.0, null),
            ]);
    }

    public function test_duplicate_grant_id_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        SpecialLeaveAccountAggregate::fake(self::USER)
            ->given([
                new SpecialLeaveAccountGrantRegistered('g1', self::TYPE, '2026-04-01', null, 3.0, null),
            ])
            ->when(function (SpecialLeaveAccountAggregate $aggregate) {
                $aggregate->registerGrant('g1', self::TYPE, '2026-04-01', null, 2.0, null);
            });
    }

    public function test_unused_grant_can_be_revoked(): void
    {
        SpecialLeaveAccountAggregate::fake(self::USER)
            ->given([
                new SpecialLeaveAccountGrantRegistered('g1', self::TYPE, '2026-04-01', null, 3.0, null),
            ])
            ->when(function (SpecialLeaveAccountAggregate $aggregate) {
                $aggregate->revokeGrant('g1', 'admin-1', '誤付与');
            })
            ->assertRecorded([
                new SpecialLeaveAccountGrantRevoked('g1', 'admin-1', '誤付与'),
            ]);
    }

    public function test_revoking_unknown_grant_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        SpecialLeaveAccountAggregate::fake(self::USER)
            ->when(function (SpecialLeaveAccountAggregate $aggregate) {
                $aggregate->revokeGrant('missing', 'admin-1', null);
            });
    }

    public function test_revoking_twice_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        SpecialLeaveAccountAggregate::fake(self::USER)
            ->given([
                new SpecialLeaveAccountGrantRegistered('g1', self::TYPE, '2026-04-01', null, 3.0, null),
                new SpecialLeaveAccountGrantRevoked('g1', 'admin-1', null),
            ])
            ->when(function (SpecialLeaveAccountAggregate $aggregate) {
                $aggregate->revokeGrant('g1', 'admin-1', null);
            });
    }

    public function test_grant_used_by_a_confirmed_usage_cannot_be_revoked(): void
    {
        $this->expectException(DomainRuleException::class);

        SpecialLeaveAccountAggregate::fake(self::USER)
            ->given([
                new SpecialLeaveAccountGrantRegistered('g1', self::TYPE, '2026-04-01', null, 3.0, null),
                new SpecialLeaveAccountUsageDesignated('u1', 'r1', self::TYPE, '2026-10-10', 'full', 1.0, 480),
                new SpecialLeaveAccountUsageConfirmed('u1', [['grantId' => 'g1', 'allocatedDays' => 1.0]]),
            ])
            ->when(function (SpecialLeaveAccountAggregate $aggregate) {
                $aggregate->revokeGrant('g1', 'admin-1', null);
            });
    }

    public function test_grant_is_revocable_again_after_its_usage_is_cancelled(): void
    {
        SpecialLeaveAccountAggregate::fake(self::USER)
            ->given([
                new SpecialLeaveAccountGrantRegistered('g1', self::TYPE, '2026-04-01', null, 3.0, null),
                new SpecialLeaveAccountUsageDesignated('u1', 'r1', self::TYPE, '2026-10-10', 'full', 1.0, 480),
                new SpecialLeaveAccountUsageConfirmed('u1', [['grantId' => 'g1', 'allocatedDays' => 1.0]]),
                new SpecialLeaveAccountUsageCancelled('u1', [['grantId' => 'g1', 'releasedDays' => 1.0]], null),
            ])
            ->when(function (SpecialLeaveAccountAggregate $aggregate) {
                $aggregate->revokeGrant('g1', 'admin-1', null);
            })
            ->assertRecorded([
                new SpecialLeaveAccountGrantRevoked('g1', 'admin-1', null),
            ]);
    }

    // ---- 申請時の消化記録 ----

    public function test_usage_is_designated_without_allocation(): void
    {
        SpecialLeaveAccountAggregate::fake(self::USER)
            ->given([
                new SpecialLeaveAccountGrantRegistered('g1', self::TYPE, '2026-04-01', null, 3.0, null),
            ])
            ->when(function (SpecialLeaveAccountAggregate $aggregate) {
                $aggregate->designateUsage('u1', 'r1', self::TYPE, '2026-10-10', 'full', 1.0, 480);
            })
            ->assertRecorded([
                new SpecialLeaveAccountUsageDesignated('u1', 'r1', self::TYPE, '2026-10-10', 'full', 1.0, 480),
            ]);
    }

    public function test_designating_with_existing_usage_id_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        SpecialLeaveAccountAggregate::fake(self::USER)
            ->given([
                new SpecialLeaveAccountUsageDesignated('u1', 'r1', self::TYPE, '2026-10-10', 'full', 1.0, 480),
            ])
            ->when(function (SpecialLeaveAccountAggregate $aggregate) {
                $aggregate->designateUsage('u1', 'r2', self::TYPE, '2026-10-11', 'full', 1.0, 480);
            });
    }

    public function test_second_active_usage_for_same_request_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        SpecialLeaveAccountAggregate::fake(self::USER)
            ->given([
                new SpecialLeaveAccountUsageDesignated('u1', 'r1', self::TYPE, '2026-10-10', 'full', 1.0, 480),
            ])
            ->when(function (SpecialLeaveAccountAggregate $aggregate) {
                $aggregate->designateUsage('u2', 'r1', self::TYPE, '2026-10-10', 'full', 1.0, 480);
            });
    }

    public function test_resubmitted_request_gets_a_new_usage_after_cancellation(): void
    {
        SpecialLeaveAccountAggregate::fake(self::USER)
            ->given([
                new SpecialLeaveAccountGrantRegistered('g1', self::TYPE, '2026-04-01', null, 3.0, null),
                new SpecialLeaveAccountUsageDesignated('u1', 'r1', self::TYPE, '2026-10-10', 'full', 1.0, 480),
                new SpecialLeaveAccountUsageCancelled('u1', [], null),
            ])
            ->when(function (SpecialLeaveAccountAggregate $aggregate) {
                $aggregate->designateUsage('u2', 'r1', self::TYPE, '2026-10-10', 'full', 1.0, 480);
            })
            ->assertRecorded([
                new SpecialLeaveAccountUsageDesignated('u2', 'r1', self::TYPE, '2026-10-10', 'full', 1.0, 480),
            ]);
    }

    // ---- 承認時の確定と充当 ----

    public function test_confirm_allocates_from_nearest_expiry_first(): void
    {
        SpecialLeaveAccountAggregate::fake(self::USER)
            ->given([
                new SpecialLeaveAccountGrantRegistered('g-late', self::TYPE, '2026-04-01', '2027-06-30', 2.0, null),
                new SpecialLeaveAccountGrantRegistered('g-early', self::TYPE, '2026-04-01', '2026-12-31', 1.0, null),
                new SpecialLeaveAccountUsageDesignated('u1', 'r1', self::TYPE, '2026-10-10', 'full', 2.0, 480),
            ])
            ->when(function (SpecialLeaveAccountAggregate $aggregate) {
                $aggregate->confirmUsage('u1', true);
            })
            ->assertRecorded([
                new SpecialLeaveAccountUsageConfirmed('u1', [
                    ['grantId' => 'g-early', 'allocatedDays' => 1.0],
                    ['grantId' => 'g-late', 'allocatedDays' => 1.0],
                ]),
            ]);
    }

    public function test_grant_without_expiry_is_used_last(): void
    {
        SpecialLeaveAccountAggregate::fake(self::USER)
            ->given([
                new SpecialLeaveAccountGrantRegistered('g-none', self::TYPE, '2026-04-01', null, 5.0, null),
                new SpecialLeaveAccountGrantRegistered('g-2027', self::TYPE, '2026-04-01', '2027-06-30', 1.0, null),
                new SpecialLeaveAccountUsageDesignated('u1', 'r1', self::TYPE, '2026-10-10', 'full', 1.0, 480),
            ])
            ->when(function (SpecialLeaveAccountAggregate $aggregate) {
                $aggregate->confirmUsage('u1', true);
            })
            ->assertRecorded([
                new SpecialLeaveAccountUsageConfirmed('u1', [
                    ['grantId' => 'g-2027', 'allocatedDays' => 1.0],
                ]),
            ]);
    }

    public function test_grants_with_same_expiry_are_used_in_registration_order(): void
    {
        SpecialLeaveAccountAggregate::fake(self::USER)
            ->given([
                new SpecialLeaveAccountGrantRegistered('g-a', self::TYPE, '2026-04-01', '2026-12-31', 1.0, null),
                new SpecialLeaveAccountGrantRegistered('g-b', self::TYPE, '2026-04-01', '2026-12-31', 1.0, null),
                new SpecialLeaveAccountUsageDesignated('u1', 'r1', self::TYPE, '2026-10-10', 'full', 1.5, 480),
            ])
            ->when(function (SpecialLeaveAccountAggregate $aggregate) {
                $aggregate->confirmUsage('u1', true);
            })
            ->assertRecorded([
                new SpecialLeaveAccountUsageConfirmed('u1', [
                    ['grantId' => 'g-a', 'allocatedDays' => 1.0],
                    ['grantId' => 'g-b', 'allocatedDays' => 0.5],
                ]),
            ]);
    }

    public function test_expired_grant_is_not_used(): void
    {
        SpecialLeaveAccountAggregate::fake(self::USER)
            ->given([
                new SpecialLeaveAccountGrantRegistered('g-expired', self::TYPE, '2025-04-01', '2026-10-09', 1.0, null),
                new SpecialLeaveAccountGrantRegistered('g-valid', self::TYPE, '2026-04-01', '2026-12-31', 1.0, null),
                new SpecialLeaveAccountUsageDesignated('u1', 'r1', self::TYPE, '2026-10-10', 'full', 1.0, 480),
            ])
            ->when(function (SpecialLeaveAccountAggregate $aggregate) {
                $aggregate->confirmUsage('u1', true);
            })
            ->assertRecorded([
                new SpecialLeaveAccountUsageConfirmed('u1', [
                    ['grantId' => 'g-valid', 'allocatedDays' => 1.0],
                ]),
            ]);
    }

    public function test_grant_expiring_on_usage_day_is_still_used(): void
    {
        SpecialLeaveAccountAggregate::fake(self::USER)
            ->given([
                new SpecialLeaveAccountGrantRegistered('g1', self::TYPE, '2026-04-01', '2026-10-10', 1.0, null),
                new SpecialLeaveAccountUsageDesignated('u1', 'r1', self::TYPE, '2026-10-10', 'full', 1.0, 480),
            ])
            ->when(function (SpecialLeaveAccountAggregate $aggregate) {
                $aggregate->confirmUsage('u1', true);
            })
            ->assertRecorded([
                new SpecialLeaveAccountUsageConfirmed('u1', [
                    ['grantId' => 'g1', 'allocatedDays' => 1.0],
                ]),
            ]);
    }

    public function test_exactly_remaining_balance_confirms(): void
    {
        SpecialLeaveAccountAggregate::fake(self::USER)
            ->given([
                new SpecialLeaveAccountGrantRegistered('g1', self::TYPE, '2026-04-01', null, 1.5, null),
                new SpecialLeaveAccountUsageDesignated('u1', 'r1', self::TYPE, '2026-10-10', 'full', 1.5, 480),
            ])
            ->when(function (SpecialLeaveAccountAggregate $aggregate) {
                $aggregate->confirmUsage('u1', true);
            })
            ->assertRecorded([
                new SpecialLeaveAccountUsageConfirmed('u1', [
                    ['grantId' => 'g1', 'allocatedDays' => 1.5],
                ]),
            ]);
    }

    public function test_confirm_partially_allocates_and_records_shortage_when_balance_is_short(): void
    {
        $unallocated = null;

        SpecialLeaveAccountAggregate::fake(self::USER)
            ->given([
                new SpecialLeaveAccountGrantRegistered('g1', self::TYPE, '2026-04-01', null, 1.0, null),
                new SpecialLeaveAccountUsageDesignated('u1', 'r1', self::TYPE, '2026-10-10', 'full', 1.5, 480),
            ])
            ->when(function (SpecialLeaveAccountAggregate $aggregate) use (&$unallocated) {
                $aggregate->confirmUsage('u1', true);

                $unallocated = $aggregate->unallocatedFor('u1');
            })
            ->assertRecorded([
                new SpecialLeaveAccountUsageConfirmed('u1', [
                    ['grantId' => 'g1', 'allocatedDays' => 1.0],
                ], 0.5),
            ]);

        $this->assertSame(0.5, $unallocated);
    }

    public function test_confirm_without_grant_allocates_nothing_and_records_full_shortage(): void
    {
        $unallocated = null;

        SpecialLeaveAccountAggregate::fake(self::USER)
            ->given([
                new SpecialLeaveAccountUsageDesignated('u1', 'r1', self::TYPE, '2026-10-10', 'full', 1.0, 480),
            ])
            ->when(function (SpecialLeaveAccountAggregate $aggregate) use (&$unallocated) {
                $aggregate->confirmUsage('u1', true);

                $unallocated = $aggregate->unallocatedFor('u1');
            })
            ->assertRecorded([
                new SpecialLeaveAccountUsageConfirmed('u1', [], 1.0),
            ]);

        $this->assertSame(1.0, $unallocated);
    }

    public function test_confirm_records_full_shortage_when_only_other_type_has_balance(): void
    {
        SpecialLeaveAccountAggregate::fake(self::USER)
            ->given([
                new SpecialLeaveAccountGrantRegistered('g-other', self::OTHER_TYPE, '2026-04-01', null, 3.0, null),
                new SpecialLeaveAccountUsageDesignated('u1', 'r1', self::TYPE, '2026-10-10', 'full', 1.0, 480),
            ])
            ->when(function (SpecialLeaveAccountAggregate $aggregate) {
                $aggregate->confirmUsage('u1', true);
            })
            ->assertRecorded([
                new SpecialLeaveAccountUsageConfirmed('u1', [], 1.0),
            ]);
    }

    public function test_confirm_does_not_use_revoked_grant_and_records_full_shortage(): void
    {
        SpecialLeaveAccountAggregate::fake(self::USER)
            ->given([
                new SpecialLeaveAccountGrantRegistered('g1', self::TYPE, '2026-04-01', null, 1.0, null),
                new SpecialLeaveAccountGrantRevoked('g1', 'admin-1', null),
                new SpecialLeaveAccountUsageDesignated('u1', 'r1', self::TYPE, '2026-10-10', 'full', 1.0, 480),
            ])
            ->when(function (SpecialLeaveAccountAggregate $aggregate) {
                $aggregate->confirmUsage('u1', true);
            })
            ->assertRecorded([
                new SpecialLeaveAccountUsageConfirmed('u1', [], 1.0),
            ]);
    }

    public function test_confirm_with_no_shortage_records_zero_unallocated(): void
    {
        $unallocated = null;

        SpecialLeaveAccountAggregate::fake(self::USER)
            ->given([
                new SpecialLeaveAccountGrantRegistered('g1', self::TYPE, '2026-04-01', null, 1.5, null),
                new SpecialLeaveAccountUsageDesignated('u1', 'r1', self::TYPE, '2026-10-10', 'full', 1.5, 480),
            ])
            ->when(function (SpecialLeaveAccountAggregate $aggregate) use (&$unallocated) {
                $aggregate->confirmUsage('u1', true);

                $unallocated = $aggregate->unallocatedFor('u1');
            });

        $this->assertSame(0.0, $unallocated);
    }

    public function test_usage_without_grant_requirement_is_confirmed_without_allocation(): void
    {
        $unallocated = null;

        SpecialLeaveAccountAggregate::fake(self::USER)
            ->given([
                new SpecialLeaveAccountUsageDesignated('u1', 'r1', self::TYPE, '2026-10-10', 'full', 1.0, 480),
            ])
            ->when(function (SpecialLeaveAccountAggregate $aggregate) use (&$unallocated) {
                $aggregate->confirmUsage('u1', false);

                $unallocated = $aggregate->unallocatedFor('u1');
            })
            ->assertRecorded([
                new SpecialLeaveAccountUsageConfirmed('u1', []),
            ]);

        $this->assertSame(0.0, $unallocated);
    }

    public function test_unallocated_is_zero_before_confirmation_and_after_cancellation(): void
    {
        $results = [];

        SpecialLeaveAccountAggregate::fake(self::USER)
            ->given([
                new SpecialLeaveAccountUsageDesignated('u1', 'r1', self::TYPE, '2026-10-10', 'full', 1.0, 480),
                new SpecialLeaveAccountUsageConfirmed('u1', [], 1.0),
                new SpecialLeaveAccountUsageDesignated('u2', 'r2', self::TYPE, '2026-10-10', 'full', 1.0, 480),
            ])
            ->when(function (SpecialLeaveAccountAggregate $aggregate) use (&$results) {
                $results['confirmed_short'] = $aggregate->unallocatedFor('u1');
                $results['designated'] = $aggregate->unallocatedFor('u2');

                $aggregate->cancelUsage('u1', '取消');

                $results['cancelled'] = $aggregate->unallocatedFor('u1');
                $results['missing'] = $aggregate->unallocatedFor('missing');
            });

        $this->assertSame([
            'confirmed_short' => 1.0,
            'designated' => 0.0,
            'cancelled' => 0.0,
            'missing' => 0.0,
        ], $results);
    }

    public function test_confirming_twice_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        SpecialLeaveAccountAggregate::fake(self::USER)
            ->given([
                new SpecialLeaveAccountGrantRegistered('g1', self::TYPE, '2026-04-01', null, 3.0, null),
                new SpecialLeaveAccountUsageDesignated('u1', 'r1', self::TYPE, '2026-10-10', 'full', 1.0, 480),
                new SpecialLeaveAccountUsageConfirmed('u1', [['grantId' => 'g1', 'allocatedDays' => 1.0]]),
            ])
            ->when(function (SpecialLeaveAccountAggregate $aggregate) {
                $aggregate->confirmUsage('u1', true);
            });
    }

    public function test_confirming_cancelled_usage_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        SpecialLeaveAccountAggregate::fake(self::USER)
            ->given([
                new SpecialLeaveAccountGrantRegistered('g1', self::TYPE, '2026-04-01', null, 3.0, null),
                new SpecialLeaveAccountUsageDesignated('u1', 'r1', self::TYPE, '2026-10-10', 'full', 1.0, 480),
                new SpecialLeaveAccountUsageCancelled('u1', [], null),
            ])
            ->when(function (SpecialLeaveAccountAggregate $aggregate) {
                $aggregate->confirmUsage('u1', true);
            });
    }

    public function test_confirming_unknown_usage_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        SpecialLeaveAccountAggregate::fake(self::USER)
            ->when(function (SpecialLeaveAccountAggregate $aggregate) {
                $aggregate->confirmUsage('missing', true);
            });
    }

    // ---- 取消と残数の戻り ----

    public function test_cancelling_unconfirmed_usage_releases_nothing(): void
    {
        SpecialLeaveAccountAggregate::fake(self::USER)
            ->given([
                new SpecialLeaveAccountGrantRegistered('g1', self::TYPE, '2026-04-01', null, 3.0, null),
                new SpecialLeaveAccountUsageDesignated('u1', 'r1', self::TYPE, '2026-10-10', 'full', 1.0, 480),
            ])
            ->when(function (SpecialLeaveAccountAggregate $aggregate) {
                $aggregate->cancelUsage('u1', '差戻し');
            })
            ->assertRecorded([
                new SpecialLeaveAccountUsageCancelled('u1', [], '差戻し'),
            ]);
    }

    public function test_cancelling_confirmed_usage_releases_allocations_and_restores_balance(): void
    {
        $remaining = null;

        SpecialLeaveAccountAggregate::fake(self::USER)
            ->given([
                new SpecialLeaveAccountGrantRegistered('g-early', self::TYPE, '2026-04-01', '2026-12-31', 1.0, null),
                new SpecialLeaveAccountGrantRegistered('g-late', self::TYPE, '2026-04-01', '2027-06-30', 2.0, null),
                new SpecialLeaveAccountUsageDesignated('u1', 'r1', self::TYPE, '2026-10-10', 'full', 2.0, 480),
                new SpecialLeaveAccountUsageConfirmed('u1', [
                    ['grantId' => 'g-early', 'allocatedDays' => 1.0],
                    ['grantId' => 'g-late', 'allocatedDays' => 1.0],
                ]),
            ])
            ->when(function (SpecialLeaveAccountAggregate $aggregate) use (&$remaining) {
                $this->assertSame(1.0, $aggregate->remaining(self::TYPE, '2026-10-10'));

                $aggregate->cancelUsage('u1', '取消');

                $remaining = $aggregate->remaining(self::TYPE, '2026-10-10');
            })
            ->assertRecorded([
                new SpecialLeaveAccountUsageCancelled('u1', [
                    ['grantId' => 'g-early', 'releasedDays' => 1.0],
                    ['grantId' => 'g-late', 'releasedDays' => 1.0],
                ], '取消'),
            ]);

        $this->assertSame(3.0, $remaining);
    }

    public function test_cancelling_twice_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        SpecialLeaveAccountAggregate::fake(self::USER)
            ->given([
                new SpecialLeaveAccountUsageDesignated('u1', 'r1', self::TYPE, '2026-10-10', 'full', 1.0, 480),
                new SpecialLeaveAccountUsageCancelled('u1', [], null),
            ])
            ->when(function (SpecialLeaveAccountAggregate $aggregate) {
                $aggregate->cancelUsage('u1', null);
            });
    }

    public function test_cancelling_unknown_usage_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        SpecialLeaveAccountAggregate::fake(self::USER)
            ->when(function (SpecialLeaveAccountAggregate $aggregate) {
                $aggregate->cancelUsage('missing', null);
            });
    }

    // ---- 残数(問い合わせ) ----

    public function test_remaining_counts_only_usable_grants_of_the_type(): void
    {
        $remaining = [];

        SpecialLeaveAccountAggregate::fake(self::USER)
            ->given([
                new SpecialLeaveAccountGrantRegistered('g-expired', self::TYPE, '2025-04-01', '2026-10-09', 4.0, null),
                new SpecialLeaveAccountGrantRegistered('g-edge', self::TYPE, '2025-04-01', '2026-10-10', 1.0, null),
                new SpecialLeaveAccountGrantRegistered('g-none', self::TYPE, '2025-04-01', null, 2.0, null),
                new SpecialLeaveAccountGrantRegistered('g-revoked', self::TYPE, '2026-04-01', null, 5.0, null),
                new SpecialLeaveAccountGrantRegistered('g-other', self::OTHER_TYPE, '2026-04-01', null, 7.0, null),
                new SpecialLeaveAccountGrantRevoked('g-revoked', 'admin-1', null),
                new SpecialLeaveAccountUsageDesignated('u1', 'r1', self::TYPE, '2026-10-10', 'full', 0.5, 240),
                new SpecialLeaveAccountUsageConfirmed('u1', [['grantId' => 'g-none', 'allocatedDays' => 0.5]]),
            ])
            ->when(function (SpecialLeaveAccountAggregate $aggregate) use (&$remaining) {
                $remaining['on_expiry_day'] = $aggregate->remaining(self::TYPE, '2026-10-10');
                $remaining['after_expiry_day'] = $aggregate->remaining(self::TYPE, '2026-10-11');
                $remaining['other_type'] = $aggregate->remaining(self::OTHER_TYPE, '2026-10-10');
            });

        // 失効日当日は有効(g-edge 1.0 + g-none 2.0 - 0.5消化 = 2.5)。失効済み・取消済みは含めない。
        $this->assertSame(2.5, $remaining['on_expiry_day']);
        // 翌日は g-edge が失効するため g-none の残り 1.5 のみ。
        $this->assertSame(1.5, $remaining['after_expiry_day']);
        $this->assertSame(7.0, $remaining['other_type']);
    }

    public function test_remaining_is_zero_for_a_fully_consumed_grant(): void
    {
        $remaining = null;

        SpecialLeaveAccountAggregate::fake(self::USER)
            ->given([
                new SpecialLeaveAccountGrantRegistered('g1', self::TYPE, '2026-04-01', null, 1.0, null),
                new SpecialLeaveAccountUsageDesignated('u1', 'r1', self::TYPE, '2026-10-10', 'full', 1.0, 480),
                new SpecialLeaveAccountUsageConfirmed('u1', [['grantId' => 'g1', 'allocatedDays' => 1.0]]),
            ])
            ->when(function (SpecialLeaveAccountAggregate $aggregate) use (&$remaining) {
                $remaining = $aggregate->remaining(self::TYPE, '2026-10-10');
            });

        $this->assertSame(0.0, $remaining);
    }

    public function test_usage_queries_reflect_state(): void
    {
        $results = [];

        SpecialLeaveAccountAggregate::fake(self::USER)
            ->given([
                new SpecialLeaveAccountUsageDesignated('u1', 'r1', self::TYPE, '2026-10-10', 'full', 1.0, 480),
                new SpecialLeaveAccountUsageCancelled('u1', [], null),
                new SpecialLeaveAccountUsageDesignated('u2', 'r1', self::TYPE, '2026-10-10', 'full', 1.0, 480),
            ])
            ->when(function (SpecialLeaveAccountAggregate $aggregate) use (&$results) {
                $results = [
                    'has_u1' => $aggregate->hasUsage('u1'),
                    'has_missing' => $aggregate->hasUsage('missing'),
                    'status_u1' => $aggregate->usageStatus('u1'),
                    'status_u2' => $aggregate->usageStatus('u2'),
                    'status_missing' => $aggregate->usageStatus('missing'),
                    'request_r1' => $aggregate->usageIdForRequest('r1'),
                    'request_missing' => $aggregate->usageIdForRequest('missing'),
                ];
            });

        $this->assertSame([
            'has_u1' => true,
            'has_missing' => false,
            'status_u1' => 'cancelled',
            'status_u2' => 'designated',
            'status_missing' => null,
            'request_r1' => 'u2',
            'request_missing' => null,
        ], $results);
    }

    // ---- 引き継ぎ(migrate) ----

    public function test_migrate_restores_grants_usages_and_allocations(): void
    {
        $results = [];

        SpecialLeaveAccountAggregate::fake(self::USER)
            ->when(function (SpecialLeaveAccountAggregate $aggregate) use (&$results) {
                $aggregate->migrate(
                    grants: [
                        ['grantId' => 'g1', 'specialLeaveTypeId' => self::TYPE, 'grantedOn' => '2026-04-01', 'expiresOn' => '2026-12-31', 'grantedDays' => 3.0, 'revoked' => false],
                        ['grantId' => 'g-rev', 'specialLeaveTypeId' => self::TYPE, 'grantedOn' => '2026-04-01', 'expiresOn' => null, 'grantedDays' => 1.0, 'revoked' => true],
                    ],
                    usages: [
                        ['usageId' => 'u1', 'requestId' => 'r1', 'specialLeaveTypeId' => self::TYPE, 'usedOn' => '2026-08-10', 'usageType' => 'full', 'usedDays' => 1.0, 'usedMinutes' => 480, 'status' => 'confirmed', 'allocations' => [['grantId' => 'g1', 'allocatedDays' => 1.0]]],
                        ['usageId' => 'u2', 'requestId' => 'r2', 'specialLeaveTypeId' => self::TYPE, 'usedOn' => '2026-08-11', 'usageType' => 'full', 'usedDays' => 0.5, 'usedMinutes' => null, 'status' => 'designated', 'allocations' => []],
                    ],
                );

                $results = [
                    'remaining' => $aggregate->remaining(self::TYPE, '2026-10-10'),
                    'status_u1' => $aggregate->usageStatus('u1'),
                    'status_u2' => $aggregate->usageStatus('u2'),
                    'request_r2' => $aggregate->usageIdForRequest('r2'),
                ];

                $aggregate->cancelUsage('u1', null);
                $results['remaining_after_cancel'] = $aggregate->remaining(self::TYPE, '2026-10-10');
            })
            ->assertRecorded([
                new SpecialLeaveAccountMigrated(
                    [
                        ['grantId' => 'g1', 'specialLeaveTypeId' => self::TYPE, 'grantedOn' => '2026-04-01', 'expiresOn' => '2026-12-31', 'grantedDays' => 3.0, 'revoked' => false],
                        ['grantId' => 'g-rev', 'specialLeaveTypeId' => self::TYPE, 'grantedOn' => '2026-04-01', 'expiresOn' => null, 'grantedDays' => 1.0, 'revoked' => true],
                    ],
                    [
                        ['usageId' => 'u1', 'requestId' => 'r1', 'specialLeaveTypeId' => self::TYPE, 'usedOn' => '2026-08-10', 'usageType' => 'full', 'usedDays' => 1.0, 'usedMinutes' => 480, 'status' => 'confirmed', 'allocations' => [['grantId' => 'g1', 'allocatedDays' => 1.0]]],
                        ['usageId' => 'u2', 'requestId' => 'r2', 'specialLeaveTypeId' => self::TYPE, 'usedOn' => '2026-08-11', 'usageType' => 'full', 'usedDays' => 0.5, 'usedMinutes' => null, 'status' => 'designated', 'allocations' => []],
                    ],
                ),
                new SpecialLeaveAccountUsageCancelled('u1', [['grantId' => 'g1', 'releasedDays' => 1.0]], null),
            ]);

        $this->assertSame([
            'remaining' => 2.0,
            'status_u1' => 'confirmed',
            'status_u2' => 'designated',
            'request_r2' => 'u2',
            'remaining_after_cancel' => 3.0,
        ], $results);
    }

    public function test_migrate_is_allowed_when_a_new_grant_was_registered_before_migration(): void
    {
        $migratedGrant = ['grantId' => 'g1', 'specialLeaveTypeId' => self::TYPE, 'grantedOn' => '2026-04-01', 'expiresOn' => null, 'grantedDays' => 3.0, 'revoked' => false];

        SpecialLeaveAccountAggregate::fake(self::USER)
            ->given([
                new SpecialLeaveAccountGrantRegistered('g0', self::TYPE, '2026-04-01', null, 1.0, null),
            ])
            ->when(function (SpecialLeaveAccountAggregate $aggregate) use ($migratedGrant) {
                $aggregate->migrate([$migratedGrant], []);
            })
            ->assertRecorded([
                new SpecialLeaveAccountMigrated([$migratedGrant], []),
            ]);
    }

    public function test_migrate_rejects_a_grant_id_that_already_exists_in_the_account(): void
    {
        $this->expectException(DomainRuleException::class);

        SpecialLeaveAccountAggregate::fake(self::USER)
            ->given([
                new SpecialLeaveAccountGrantRegistered('g0', self::TYPE, '2026-04-01', null, 1.0, null),
            ])
            ->when(function (SpecialLeaveAccountAggregate $aggregate) {
                $aggregate->migrate([
                    ['grantId' => 'g0', 'specialLeaveTypeId' => self::TYPE, 'grantedOn' => '2026-04-01', 'expiresOn' => null, 'grantedDays' => 1.0, 'revoked' => false],
                ], []);
            });
    }

    public function test_second_migrate_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        SpecialLeaveAccountAggregate::fake(self::USER)
            ->given([
                new SpecialLeaveAccountMigrated([], []),
            ])
            ->when(function (SpecialLeaveAccountAggregate $aggregate) {
                $aggregate->migrate([], []);
            });
    }

    public function test_migrate_rejects_allocation_to_unknown_grant(): void
    {
        $this->expectException(DomainRuleException::class);

        SpecialLeaveAccountAggregate::fake(self::USER)
            ->when(function (SpecialLeaveAccountAggregate $aggregate) {
                $aggregate->migrate(
                    grants: [],
                    usages: [
                        ['usageId' => 'u1', 'requestId' => 'r1', 'specialLeaveTypeId' => self::TYPE, 'usedOn' => '2026-08-10', 'usageType' => 'full', 'usedDays' => 1.0, 'usedMinutes' => 480, 'status' => 'confirmed', 'allocations' => [['grantId' => 'missing', 'allocatedDays' => 1.0]]],
                    ],
                );
            });
    }

    public function test_migrate_rejects_allocation_exceeding_grant(): void
    {
        $this->expectException(DomainRuleException::class);

        SpecialLeaveAccountAggregate::fake(self::USER)
            ->when(function (SpecialLeaveAccountAggregate $aggregate) {
                $aggregate->migrate(
                    grants: [
                        ['grantId' => 'g1', 'specialLeaveTypeId' => self::TYPE, 'grantedOn' => '2026-04-01', 'expiresOn' => null, 'grantedDays' => 1.0, 'revoked' => false],
                    ],
                    usages: [
                        ['usageId' => 'u1', 'requestId' => 'r1', 'specialLeaveTypeId' => self::TYPE, 'usedOn' => '2026-08-10', 'usageType' => 'full', 'usedDays' => 1.0, 'usedMinutes' => 480, 'status' => 'confirmed', 'allocations' => [['grantId' => 'g1', 'allocatedDays' => 1.0]]],
                        ['usageId' => 'u2', 'requestId' => 'r2', 'specialLeaveTypeId' => self::TYPE, 'usedOn' => '2026-08-11', 'usageType' => 'full', 'usedDays' => 1.0, 'usedMinutes' => 480, 'status' => 'confirmed', 'allocations' => [['grantId' => 'g1', 'allocatedDays' => 1.0]]],
                    ],
                );
            });
    }

    public function test_migrate_rejects_duplicate_request_id(): void
    {
        $this->expectException(DomainRuleException::class);

        SpecialLeaveAccountAggregate::fake(self::USER)
            ->when(function (SpecialLeaveAccountAggregate $aggregate) {
                $aggregate->migrate(
                    grants: [],
                    usages: [
                        ['usageId' => 'u1', 'requestId' => 'r1', 'specialLeaveTypeId' => self::TYPE, 'usedOn' => '2026-08-10', 'usageType' => 'full', 'usedDays' => 1.0, 'usedMinutes' => 480, 'status' => 'designated', 'allocations' => []],
                        ['usageId' => 'u2', 'requestId' => 'r1', 'specialLeaveTypeId' => self::TYPE, 'usedOn' => '2026-08-11', 'usageType' => 'full', 'usedDays' => 1.0, 'usedMinutes' => 480, 'status' => 'designated', 'allocations' => []],
                    ],
                );
            });
    }

    public function test_migrate_rejects_designated_usage_with_allocation(): void
    {
        $this->expectException(DomainRuleException::class);

        SpecialLeaveAccountAggregate::fake(self::USER)
            ->when(function (SpecialLeaveAccountAggregate $aggregate) {
                $aggregate->migrate(
                    grants: [
                        ['grantId' => 'g1', 'specialLeaveTypeId' => self::TYPE, 'grantedOn' => '2026-04-01', 'expiresOn' => null, 'grantedDays' => 1.0, 'revoked' => false],
                    ],
                    usages: [
                        ['usageId' => 'u1', 'requestId' => 'r1', 'specialLeaveTypeId' => self::TYPE, 'usedOn' => '2026-08-10', 'usageType' => 'full', 'usedDays' => 1.0, 'usedMinutes' => 480, 'status' => 'designated', 'allocations' => [['grantId' => 'g1', 'allocatedDays' => 1.0]]],
                    ],
                );
            });
    }

    public function test_migrate_rejects_allocation_to_grant_of_other_type(): void
    {
        $this->expectException(DomainRuleException::class);

        SpecialLeaveAccountAggregate::fake(self::USER)
            ->when(function (SpecialLeaveAccountAggregate $aggregate) {
                $aggregate->migrate(
                    grants: [
                        ['grantId' => 'g1', 'specialLeaveTypeId' => self::OTHER_TYPE, 'grantedOn' => '2026-04-01', 'expiresOn' => null, 'grantedDays' => 1.0, 'revoked' => false],
                    ],
                    usages: [
                        ['usageId' => 'u1', 'requestId' => 'r1', 'specialLeaveTypeId' => self::TYPE, 'usedOn' => '2026-08-10', 'usageType' => 'full', 'usedDays' => 1.0, 'usedMinutes' => 480, 'status' => 'confirmed', 'allocations' => [['grantId' => 'g1', 'allocatedDays' => 1.0]]],
                    ],
                );
            });
    }
}
