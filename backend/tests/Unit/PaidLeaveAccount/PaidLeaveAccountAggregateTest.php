<?php

namespace Tests\Unit\PaidLeaveAccount;

use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\PaidLeaveAccount\Aggregates\PaidLeaveAccountAggregate;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveGrantAmountChanged;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveGrantCreated;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveGrantDateChanged;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveGrantExpiryChanged;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveGrantRevoked;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveUsageAllocated;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveUsageAllocationReleased;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveUsageCancelled;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveUsageConfirmed;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveUsageDesignated;
use Tests\TestCase;

/**
 * PaidLeaveAccountAggregateの単体テスト。すべてEvent→Aggregate replay→Commandの結果を
 * 検証する形式で行い、Projection(Eloquent)は一切使わない
 * (docs/changesets/20260906-paid-leave-domain-redesign/spec.md 検証方法)。
 */
class PaidLeaveAccountAggregateTest extends TestCase
{
    private const USER = 'user-1';

    // ---- Grant: 初回・後続・順序制約 ----

    public function test_first_grant_is_recorded(): void
    {
        PaidLeaveAccountAggregate::fake(self::USER)
            ->when(function (PaidLeaveAccountAggregate $aggregate) {
                $aggregate->grant('g1', '2025-04-01', '2027-04-01', 10.0, '定期付与', 'manual');
            })
            ->assertRecorded([
                new PaidLeaveGrantCreated('g1', '2025-04-01', '2027-04-01', 10.0, '定期付与', 'manual'),
            ]);
    }

    public function test_subsequent_grant_after_latest_is_allowed(): void
    {
        PaidLeaveAccountAggregate::fake(self::USER)
            ->given([
                new PaidLeaveGrantCreated('g1', '2025-04-01', '2027-04-01', 10.0, null, 'manual'),
            ])
            ->when(function (PaidLeaveAccountAggregate $aggregate) {
                $aggregate->grant('g2', '2026-04-01', '2028-04-01', 11.0, null, 'manual');
            })
            ->assertRecorded([
                new PaidLeaveGrantCreated('g2', '2026-04-01', '2028-04-01', 11.0, null, 'manual'),
            ]);
    }

    public function test_same_day_grant_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        PaidLeaveAccountAggregate::fake(self::USER)
            ->given([
                new PaidLeaveGrantCreated('g1', '2025-04-01', '2027-04-01', 10.0, null, 'manual'),
            ])
            ->when(function (PaidLeaveAccountAggregate $aggregate) {
                $aggregate->grant('g2', '2025-04-01', '2027-04-01', 11.0, null, 'manual');
            });
    }

    public function test_past_dated_grant_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        PaidLeaveAccountAggregate::fake(self::USER)
            ->given([
                new PaidLeaveGrantCreated('g1', '2025-04-01', '2027-04-01', 10.0, null, 'manual'),
            ])
            ->when(function (PaidLeaveAccountAggregate $aggregate) {
                $aggregate->grant('g2', '2025-01-01', '2027-01-01', 11.0, null, 'manual');
            });
    }

    public function test_overlapping_grant_validity_periods_are_allowed(): void
    {
        // grantedOnは常に増加しているが、expiresOnの前後関係は問わない。
        PaidLeaveAccountAggregate::fake(self::USER)
            ->given([
                new PaidLeaveGrantCreated('g1', '2025-04-01', '2028-04-01', 10.0, null, 'manual'),
            ])
            ->when(function (PaidLeaveAccountAggregate $aggregate) {
                $aggregate->grant('g2', '2026-04-01', '2027-04-01', 11.0, null, 'manual');
            })
            ->assertRecorded([
                new PaidLeaveGrantCreated('g2', '2026-04-01', '2027-04-01', 11.0, null, 'manual'),
            ]);
    }

    // ---- Grant: 最新Grantのみ変更・取消 ----

    public function test_latest_grant_amount_change_is_allowed(): void
    {
        PaidLeaveAccountAggregate::fake(self::USER)
            ->given([
                new PaidLeaveGrantCreated('g1', '2025-04-01', '2027-04-01', 10.0, null, 'manual'),
                new PaidLeaveGrantCreated('g2', '2026-04-01', '2028-04-01', 11.0, null, 'manual'),
            ])
            ->when(function (PaidLeaveAccountAggregate $aggregate) {
                $aggregate->changeGrantAmount('g2', 12.0, '調整', 'admin-1');
            })
            ->assertRecorded([
                new PaidLeaveGrantAmountChanged('g2', 12.0, '調整', 'admin-1'),
            ]);
    }

    public function test_non_latest_grant_amount_change_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        PaidLeaveAccountAggregate::fake(self::USER)
            ->given([
                new PaidLeaveGrantCreated('g1', '2025-04-01', '2027-04-01', 10.0, null, 'manual'),
                new PaidLeaveGrantCreated('g2', '2026-04-01', '2028-04-01', 11.0, null, 'manual'),
            ])
            ->when(function (PaidLeaveAccountAggregate $aggregate) {
                $aggregate->changeGrantAmount('g1', 12.0, '調整', 'admin-1');
            });
    }

    public function test_latest_grant_date_change_is_allowed(): void
    {
        PaidLeaveAccountAggregate::fake(self::USER)
            ->given([
                new PaidLeaveGrantCreated('g1', '2025-04-01', '2027-04-01', 10.0, null, 'manual'),
                new PaidLeaveGrantCreated('g2', '2026-04-01', '2028-04-01', 11.0, null, 'manual'),
            ])
            ->when(function (PaidLeaveAccountAggregate $aggregate) {
                $aggregate->changeGrantDate('g2', '2026-05-01', '訂正', 'admin-1');
            })
            ->assertRecorded([
                new PaidLeaveGrantDateChanged('g2', '2026-05-01', '訂正', 'admin-1'),
            ]);
    }

    public function test_non_latest_grant_date_change_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        PaidLeaveAccountAggregate::fake(self::USER)
            ->given([
                new PaidLeaveGrantCreated('g1', '2025-04-01', '2027-04-01', 10.0, null, 'manual'),
                new PaidLeaveGrantCreated('g2', '2026-04-01', '2028-04-01', 11.0, null, 'manual'),
            ])
            ->when(function (PaidLeaveAccountAggregate $aggregate) {
                $aggregate->changeGrantDate('g1', '2025-05-01', '訂正', 'admin-1');
            });
    }

    public function test_latest_grant_expiry_change_is_allowed(): void
    {
        PaidLeaveAccountAggregate::fake(self::USER)
            ->given([
                new PaidLeaveGrantCreated('g1', '2025-04-01', '2027-04-01', 10.0, null, 'manual'),
            ])
            ->when(function (PaidLeaveAccountAggregate $aggregate) {
                $aggregate->changeGrantExpiry('g1', '2027-05-01', '延長', 'admin-1');
            })
            ->assertRecorded([
                new PaidLeaveGrantExpiryChanged('g1', '2027-05-01', '延長', 'admin-1'),
            ]);
    }

    public function test_change_grant_expiry_rejected_when_new_expiry_precedes_own_granted_on(): void
    {
        // Allocation件数0なので短縮チェックは通るが、grantedOn(2025-04-01)より前の
        // expiresOnはgrantedOn<=expiresOnの不変条件に違反するため拒否されるべき。
        $this->expectException(DomainRuleException::class);

        PaidLeaveAccountAggregate::fake(self::USER)
            ->given([
                new PaidLeaveGrantCreated('g1', '2025-04-01', '2027-04-01', 10.0, null, 'manual'),
            ])
            ->when(function (PaidLeaveAccountAggregate $aggregate) {
                $aggregate->changeGrantExpiry('g1', '2025-01-01', '誤入力', 'admin-1');
            });
    }

    public function test_change_grant_expiry_to_exactly_granted_on_is_allowed(): void
    {
        PaidLeaveAccountAggregate::fake(self::USER)
            ->given([
                new PaidLeaveGrantCreated('g1', '2025-04-01', '2027-04-01', 10.0, null, 'manual'),
            ])
            ->when(function (PaidLeaveAccountAggregate $aggregate) {
                $aggregate->changeGrantExpiry('g1', '2025-04-01', '短縮', 'admin-1');
            })
            ->assertRecorded([
                new PaidLeaveGrantExpiryChanged('g1', '2025-04-01', '短縮', 'admin-1'),
            ]);
    }

    public function test_change_grant_date_rejected_when_new_granted_on_exceeds_own_expires_on(): void
    {
        $this->expectException(DomainRuleException::class);

        PaidLeaveAccountAggregate::fake(self::USER)
            ->given([
                new PaidLeaveGrantCreated('g1', '2025-04-01', '2027-04-01', 10.0, null, 'manual'),
            ])
            ->when(function (PaidLeaveAccountAggregate $aggregate) {
                $aggregate->changeGrantDate('g1', '2027-05-01', '誤入力', 'admin-1');
            });
    }

    public function test_change_grant_date_to_exactly_own_expires_on_is_allowed(): void
    {
        PaidLeaveAccountAggregate::fake(self::USER)
            ->given([
                new PaidLeaveGrantCreated('g1', '2025-04-01', '2027-04-01', 10.0, null, 'manual'),
            ])
            ->when(function (PaidLeaveAccountAggregate $aggregate) {
                $aggregate->changeGrantDate('g1', '2027-04-01', '訂正', 'admin-1');
            })
            ->assertRecorded([
                new PaidLeaveGrantDateChanged('g1', '2027-04-01', '訂正', 'admin-1'),
            ]);
    }

    // ---- Grant: 取消(スタック解除) ----

    public function test_latest_grant_revoke_releases_no_allocations_when_none_exist(): void
    {
        PaidLeaveAccountAggregate::fake(self::USER)
            ->given([
                new PaidLeaveGrantCreated('g1', '2025-04-01', '2027-04-01', 10.0, null, 'manual'),
            ])
            ->when(function (PaidLeaveAccountAggregate $aggregate) {
                $aggregate->revokeGrant('g1', 'admin-1', '誤付与');
            })
            ->assertRecorded([
                new PaidLeaveGrantRevoked('g1', 'admin-1', '誤付与'),
            ]);
    }

    public function test_revoking_latest_grant_makes_previous_grant_latest_again(): void
    {
        PaidLeaveAccountAggregate::fake(self::USER)
            ->given([
                new PaidLeaveGrantCreated('g1', '2025-04-01', '2027-04-01', 10.0, null, 'manual'),
                new PaidLeaveGrantCreated('g2', '2026-04-01', '2028-04-01', 11.0, null, 'manual'),
            ])
            ->when(function (PaidLeaveAccountAggregate $aggregate) {
                $aggregate->revokeGrant('g2', 'admin-1', null);
                // g2取消後はg1が最新となり、変更可能になる。
                $aggregate->changeGrantAmount('g1', 9.0, null, 'admin-1');
            })
            ->assertRecorded([
                new PaidLeaveGrantRevoked('g2', 'admin-1', null),
                new PaidLeaveGrantAmountChanged('g1', 9.0, null, 'admin-1'),
            ]);
    }

    public function test_consecutive_revokes_are_allowed(): void
    {
        PaidLeaveAccountAggregate::fake(self::USER)
            ->given([
                new PaidLeaveGrantCreated('g1', '2025-04-01', '2027-04-01', 10.0, null, 'manual'),
                new PaidLeaveGrantCreated('g2', '2026-04-01', '2028-04-01', 11.0, null, 'manual'),
            ])
            ->when(function (PaidLeaveAccountAggregate $aggregate) {
                $aggregate->revokeGrant('g2', 'admin-1', null);
                $aggregate->revokeGrant('g1', 'admin-1', null);
            })
            ->assertRecorded([
                new PaidLeaveGrantRevoked('g2', 'admin-1', null),
                new PaidLeaveGrantRevoked('g1', 'admin-1', null),
            ]);
    }

    // ---- Grant: 増額・減額 ----

    public function test_grant_amount_increase_is_allowed(): void
    {
        PaidLeaveAccountAggregate::fake(self::USER)
            ->given([
                new PaidLeaveGrantCreated('g1', '2025-04-01', '2027-04-01', 10.0, null, 'manual'),
            ])
            ->when(function (PaidLeaveAccountAggregate $aggregate) {
                $aggregate->changeGrantAmount('g1', 15.0, null, 'admin-1');
            })
            ->assertRecorded([
                new PaidLeaveGrantAmountChanged('g1', 15.0, null, 'admin-1'),
            ]);
    }

    public function test_grant_amount_decrease_below_allocated_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        PaidLeaveAccountAggregate::fake(self::USER)
            ->given([
                new PaidLeaveGrantCreated('g1', '2025-04-01', '2027-04-01', 10.0, null, 'manual'),
                new PaidLeaveUsageDesignated('u1', null, null, '2025-05-01', 5.0),
                new PaidLeaveUsageConfirmed('u1', 'admin-1'),
                new PaidLeaveUsageAllocated('u1', 'g1', 5.0),
            ])
            ->when(function (PaidLeaveAccountAggregate $aggregate) {
                $aggregate->changeGrantAmount('g1', 4.0, null, 'admin-1');
            });
    }

    public function test_grant_amount_decrease_to_exactly_allocated_is_allowed(): void
    {
        PaidLeaveAccountAggregate::fake(self::USER)
            ->given([
                new PaidLeaveGrantCreated('g1', '2025-04-01', '2027-04-01', 10.0, null, 'manual'),
                new PaidLeaveUsageDesignated('u1', null, null, '2025-05-01', 5.0),
                new PaidLeaveUsageConfirmed('u1', 'admin-1'),
                new PaidLeaveUsageAllocated('u1', 'g1', 5.0),
            ])
            ->when(function (PaidLeaveAccountAggregate $aggregate) {
                $aggregate->changeGrantAmount('g1', 5.0, null, 'admin-1');
            })
            ->assertRecorded([
                new PaidLeaveGrantAmountChanged('g1', 5.0, null, 'admin-1'),
            ]);
    }

    // ---- Grant: 有効期限短縮・延長 ----

    public function test_expiry_shortening_with_zero_allocations_is_allowed(): void
    {
        PaidLeaveAccountAggregate::fake(self::USER)
            ->given([
                new PaidLeaveGrantCreated('g1', '2025-04-01', '2027-04-01', 10.0, null, 'manual'),
            ])
            ->when(function (PaidLeaveAccountAggregate $aggregate) {
                $aggregate->changeGrantExpiry('g1', '2026-04-01', '短縮', 'admin-1');
            })
            ->assertRecorded([
                new PaidLeaveGrantExpiryChanged('g1', '2026-04-01', '短縮', 'admin-1'),
            ]);
    }

    public function test_expiry_shortening_with_allocations_is_rejected(): void
    {
        $this->expectException(DomainRuleException::class);

        PaidLeaveAccountAggregate::fake(self::USER)
            ->given([
                new PaidLeaveGrantCreated('g1', '2025-04-01', '2027-04-01', 10.0, null, 'manual'),
                new PaidLeaveUsageDesignated('u1', null, null, '2025-05-01', 5.0),
                new PaidLeaveUsageConfirmed('u1', 'admin-1'),
                new PaidLeaveUsageAllocated('u1', 'g1', 5.0),
            ])
            ->when(function (PaidLeaveAccountAggregate $aggregate) {
                $aggregate->changeGrantExpiry('g1', '2026-04-01', '短縮', 'admin-1');
            });
    }

    // ---- Usage: 申請・確定・取消 ----

    public function test_usage_is_designated_on_request(): void
    {
        PaidLeaveAccountAggregate::fake(self::USER)
            ->given([
                new PaidLeaveGrantCreated('g1', '2025-04-01', '2027-04-01', 10.0, null, 'manual'),
            ])
            ->when(function (PaidLeaveAccountAggregate $aggregate) {
                $aggregate->designateUsage('u1', 'wf-1', 'day-1', '2025-05-01', 1.0, 'full');
            })
            ->assertRecorded([
                new PaidLeaveUsageDesignated('u1', 'wf-1', 'day-1', '2025-05-01', 1.0),
            ]);
    }

    public function test_half_day_usage_is_designated(): void
    {
        PaidLeaveAccountAggregate::fake(self::USER)
            ->given([
                new PaidLeaveGrantCreated('g1', '2025-04-01', '2027-04-01', 10.0, null, 'manual'),
            ])
            ->when(function (PaidLeaveAccountAggregate $aggregate) {
                $aggregate->designateUsage('u1', 'wf-1', 'day-1', '2025-05-01', 0.5, 'am_half');
            })
            ->assertRecorded([
                new PaidLeaveUsageDesignated('u1', 'wf-1', 'day-1', '2025-05-01', 0.5, 'am_half'),
            ]);
    }

    public function test_cancel_of_unconfirmed_usage_is_allowed(): void
    {
        PaidLeaveAccountAggregate::fake(self::USER)
            ->given([
                new PaidLeaveGrantCreated('g1', '2025-04-01', '2027-04-01', 10.0, null, 'manual'),
                new PaidLeaveUsageDesignated('u1', 'wf-1', 'day-1', '2025-05-01', 1.0),
            ])
            ->when(function (PaidLeaveAccountAggregate $aggregate) {
                $aggregate->cancelUsage('u1', 'user-1', '取下げ');
            })
            ->assertRecorded([
                new PaidLeaveUsageCancelled('u1', 'user-1', '取下げ'),
            ]);
    }

    public function test_resubmission_creates_a_new_usage_never_reuses(): void
    {
        PaidLeaveAccountAggregate::fake(self::USER)
            ->given([
                new PaidLeaveGrantCreated('g1', '2025-04-01', '2027-04-01', 10.0, null, 'manual'),
                new PaidLeaveUsageDesignated('u1', 'wf-1', 'day-1', '2025-05-01', 1.0),
                new PaidLeaveUsageCancelled('u1', 'user-1', '差戻し'),
            ])
            ->when(function (PaidLeaveAccountAggregate $aggregate) {
                $aggregate->designateUsage('u2', 'wf-2', 'day-1', '2025-05-01', 1.0, 'full');
            })
            ->assertRecorded([
                new PaidLeaveUsageDesignated('u2', 'wf-2', 'day-1', '2025-05-01', 1.0),
            ]);
    }

    public function test_confirm_on_approval_allocates(): void
    {
        PaidLeaveAccountAggregate::fake(self::USER)
            ->given([
                new PaidLeaveGrantCreated('g1', '2025-04-01', '2027-04-01', 10.0, null, 'manual'),
                new PaidLeaveUsageDesignated('u1', 'wf-1', 'day-1', '2025-05-01', 1.0),
            ])
            ->when(function (PaidLeaveAccountAggregate $aggregate) {
                $aggregate->confirmUsage('u1', 'approver-1');
            })
            ->assertRecorded([
                new PaidLeaveUsageConfirmed('u1', 'approver-1'),
                new PaidLeaveUsageAllocated('u1', 'g1', 1.0),
            ]);
    }

    // ---- Allocation: Grant選択順序 ----

    public function test_usedon_valid_grant_selected_by_nearest_expiry(): void
    {
        PaidLeaveAccountAggregate::fake(self::USER)
            ->given([
                new PaidLeaveGrantCreated('g1', '2024-04-01', '2026-04-01', 10.0, null, 'manual'),
                new PaidLeaveGrantCreated('g2', '2025-04-01', '2027-04-01', 10.0, null, 'manual'),
                new PaidLeaveUsageDesignated('u1', 'wf-1', 'day-1', '2025-05-01', 3.0),
            ])
            ->when(function (PaidLeaveAccountAggregate $aggregate) {
                $aggregate->confirmUsage('u1', 'approver-1');
            })
            ->assertRecorded([
                new PaidLeaveUsageConfirmed('u1', 'approver-1'),
                // 2026-04-01失効のg1の方がexpiresOnが近いため優先充当される。
                new PaidLeaveUsageAllocated('u1', 'g1', 3.0),
            ]);
    }

    public function test_usedon_valid_grant_despite_now_calendar_expired(): void
    {
        // 現在の暦日ではg1は失効しているが、usedOn時点では有効だったため充当対象になる。
        PaidLeaveAccountAggregate::fake(self::USER)
            ->given([
                new PaidLeaveGrantCreated('g1', '2020-04-01', '2022-04-01', 10.0, null, 'manual'),
                new PaidLeaveUsageDesignated('u1', 'wf-1', 'day-1', '2021-05-01', 3.0),
            ])
            ->when(function (PaidLeaveAccountAggregate $aggregate) {
                $aggregate->confirmUsage('u1', 'approver-1');
            })
            ->assertRecorded([
                new PaidLeaveUsageConfirmed('u1', 'approver-1'),
                new PaidLeaveUsageAllocated('u1', 'g1', 3.0),
            ]);
    }

    public function test_usage_spans_multiple_grants(): void
    {
        PaidLeaveAccountAggregate::fake(self::USER)
            ->given([
                new PaidLeaveGrantCreated('g1', '2024-04-01', '2025-06-01', 2.0, null, 'manual'),
                new PaidLeaveGrantCreated('g2', '2025-04-01', '2027-04-01', 10.0, null, 'manual'),
                new PaidLeaveUsageDesignated('u1', 'wf-1', 'day-1', '2025-05-01', 5.0),
            ])
            ->when(function (PaidLeaveAccountAggregate $aggregate) {
                $aggregate->confirmUsage('u1', 'approver-1');
            })
            ->assertRecorded([
                new PaidLeaveUsageConfirmed('u1', 'approver-1'),
                new PaidLeaveUsageAllocated('u1', 'g1', 2.0),
                new PaidLeaveUsageAllocated('u1', 'g2', 3.0),
            ]);
    }

    public function test_exactly_one_nearest_future_grant_only(): void
    {
        // usedOnより後に付与された g2(近い) / g3(遠い) のうち g2 のみが対象。
        PaidLeaveAccountAggregate::fake(self::USER)
            ->given([
                new PaidLeaveGrantCreated('g1', '2024-04-01', '2025-01-01', 1.0, null, 'manual'),
                new PaidLeaveGrantCreated('g2', '2025-06-01', '2027-06-01', 10.0, null, 'manual'),
                new PaidLeaveGrantCreated('g3', '2025-07-01', '2027-07-01', 10.0, null, 'manual'),
                new PaidLeaveUsageDesignated('u1', 'wf-1', 'day-1', '2025-05-01', 3.0),
            ])
            ->when(function (PaidLeaveAccountAggregate $aggregate) {
                $aggregate->confirmUsage('u1', 'approver-1');
            })
            ->assertRecorded([
                new PaidLeaveUsageConfirmed('u1', 'approver-1'),
                new PaidLeaveUsageAllocated('u1', 'g2', 3.0),
            ]);
    }

    public function test_remainder_left_unallocated_without_failing_confirm(): void
    {
        PaidLeaveAccountAggregate::fake(self::USER)
            ->given([
                new PaidLeaveGrantCreated('g1', '2024-04-01', '2025-01-01', 1.0, null, 'manual'),
                new PaidLeaveUsageDesignated('u1', 'wf-1', 'day-1', '2025-05-01', 3.0),
            ])
            ->when(function (PaidLeaveAccountAggregate $aggregate) {
                $aggregate->confirmUsage('u1', 'approver-1');
            })
            ->assertRecorded([
                new PaidLeaveUsageConfirmed('u1', 'approver-1'),
            ]);
    }

    // ---- Allocation: 自動再Allocation ----

    public function test_grant_increase_triggers_auto_allocation_of_unallocated_usages(): void
    {
        PaidLeaveAccountAggregate::fake(self::USER)
            ->given([
                new PaidLeaveGrantCreated('g1', '2024-04-01', '2026-04-01', 1.0, null, 'manual'),
                new PaidLeaveUsageDesignated('u1', 'wf-1', 'day-1', '2025-05-01', 3.0),
                new PaidLeaveUsageConfirmed('u1', 'approver-1'),
                new PaidLeaveUsageAllocated('u1', 'g1', 1.0),
            ])
            ->when(function (PaidLeaveAccountAggregate $aggregate) {
                $aggregate->changeGrantAmount('g1', 4.0, '増額', 'admin-1');
            })
            ->assertRecorded([
                new PaidLeaveGrantAmountChanged('g1', 4.0, '増額', 'admin-1'),
                new PaidLeaveUsageAllocated('u1', 'g1', 2.0),
            ]);
    }

    public function test_new_grant_triggers_auto_allocation(): void
    {
        PaidLeaveAccountAggregate::fake(self::USER)
            ->given([
                new PaidLeaveGrantCreated('g1', '2024-04-01', '2025-01-01', 1.0, null, 'manual'),
                new PaidLeaveUsageDesignated('u1', 'wf-1', 'day-1', '2025-05-01', 3.0),
                new PaidLeaveUsageConfirmed('u1', 'approver-1'),
            ])
            ->when(function (PaidLeaveAccountAggregate $aggregate) {
                $aggregate->grant('g2', '2025-06-01', '2027-06-01', 10.0, null, 'manual');
            })
            ->assertRecorded([
                new PaidLeaveGrantCreated('g2', '2025-06-01', '2027-06-01', 10.0, null, 'manual'),
                new PaidLeaveUsageAllocated('u1', 'g2', 3.0),
            ]);
    }

    public function test_expiry_extension_triggers_auto_allocation(): void
    {
        PaidLeaveAccountAggregate::fake(self::USER)
            ->given([
                new PaidLeaveGrantCreated('g1', '2024-04-01', '2025-01-01', 5.0, null, 'manual'),
                new PaidLeaveUsageDesignated('u1', 'wf-1', 'day-1', '2025-05-01', 3.0),
                new PaidLeaveUsageConfirmed('u1', 'approver-1'),
            ])
            ->when(function (PaidLeaveAccountAggregate $aggregate) {
                // g1のexpiresOnをusedOnより後まで延長 -> usedOn時点でも有効になり充当対象になる。
                $aggregate->changeGrantExpiry('g1', '2027-01-01', '延長', 'admin-1');
            })
            ->assertRecorded([
                new PaidLeaveGrantExpiryChanged('g1', '2027-01-01', '延長', 'admin-1'),
                new PaidLeaveUsageAllocated('u1', 'g1', 3.0),
            ]);
    }

    public function test_usage_cancellation_frees_allocation_and_triggers_auto_allocation_for_others(): void
    {
        PaidLeaveAccountAggregate::fake(self::USER)
            ->given([
                new PaidLeaveGrantCreated('g1', '2024-04-01', '2027-04-01', 3.0, null, 'manual'),
                new PaidLeaveUsageDesignated('u1', 'wf-1', 'day-1', '2025-05-01', 3.0),
                new PaidLeaveUsageConfirmed('u1', 'approver-1'),
                new PaidLeaveUsageAllocated('u1', 'g1', 3.0),
                new PaidLeaveUsageDesignated('u2', 'wf-2', 'day-2', '2025-06-01', 2.0),
                new PaidLeaveUsageConfirmed('u2', 'approver-1'),
            ])
            ->when(function (PaidLeaveAccountAggregate $aggregate) {
                $aggregate->cancelUsage('u1', 'user-1', '取消申請承認');
            })
            ->assertRecorded([
                new PaidLeaveUsageAllocationReleased('u1', 'g1', 3.0),
                new PaidLeaveUsageCancelled('u1', 'user-1', '取消申請承認'),
                new PaidLeaveUsageAllocated('u2', 'g1', 2.0),
            ]);
    }

    public function test_auto_allocation_processes_by_usedon_ascending(): void
    {
        // u2の方が後に確定されたが usedOn は u1 より早いため、u2 が先に充当される。
        PaidLeaveAccountAggregate::fake(self::USER)
            ->given([
                new PaidLeaveGrantCreated('g1', '2024-04-01', '2027-04-01', 2.0, null, 'manual'),
                new PaidLeaveUsageDesignated('u1', 'wf-1', 'day-1', '2025-06-01', 2.0),
                new PaidLeaveUsageDesignated('u2', 'wf-2', 'day-2', '2025-05-01', 2.0),
            ])
            ->when(function (PaidLeaveAccountAggregate $aggregate) {
                $aggregate->confirmUsage('u1', 'approver-1');
                $aggregate->confirmUsage('u2', 'approver-1');
            })
            ->assertRecorded([
                new PaidLeaveUsageConfirmed('u1', 'approver-1'),
                new PaidLeaveUsageAllocated('u1', 'g1', 2.0),
                new PaidLeaveUsageConfirmed('u2', 'approver-1'),
                // g1は既にu1へ全量充当済みのため、u2は充当されないまま残る。
            ]);
    }

    public function test_later_created_but_earlier_usedon_usage_does_not_reshuffle_allocated_later_usage(): void
    {
        PaidLeaveAccountAggregate::fake(self::USER)
            ->given([
                new PaidLeaveGrantCreated('g1', '2024-04-01', '2027-04-01', 2.0, null, 'manual'),
                new PaidLeaveUsageDesignated('u1', 'wf-1', 'day-1', '2025-06-01', 2.0),
                new PaidLeaveUsageConfirmed('u1', 'approver-1'),
                new PaidLeaveUsageAllocated('u1', 'g1', 2.0),
            ])
            ->when(function (PaidLeaveAccountAggregate $aggregate) {
                // u2はu1よりusedOnが早いが、g1に空きがないため既存のu1へのAllocationは
                // 組み替えられず、u2は未充当のまま残る。
                $aggregate->designateUsage('u2', 'wf-2', 'day-2', '2025-05-01', 1.0, 'full');
                $aggregate->confirmUsage('u2', 'approver-1');
            })
            ->assertRecorded([
                new PaidLeaveUsageDesignated('u2', 'wf-2', 'day-2', '2025-05-01', 1.0),
                new PaidLeaveUsageConfirmed('u2', 'approver-1'),
            ]);
    }

    public function test_grant_revoke_releases_all_its_allocations_without_touching_usage_record(): void
    {
        PaidLeaveAccountAggregate::fake(self::USER)
            ->given([
                new PaidLeaveGrantCreated('g1', '2024-04-01', '2027-04-01', 3.0, null, 'manual'),
                new PaidLeaveUsageDesignated('u1', 'wf-1', 'day-1', '2025-05-01', 3.0),
                new PaidLeaveUsageConfirmed('u1', 'approver-1'),
                new PaidLeaveUsageAllocated('u1', 'g1', 3.0),
            ])
            ->when(function (PaidLeaveAccountAggregate $aggregate) {
                $aggregate->revokeGrant('g1', 'admin-1', '誤付与取消');
            })
            ->assertRecorded([
                new PaidLeaveUsageAllocationReleased('u1', 'g1', 3.0),
                new PaidLeaveGrantRevoked('g1', 'admin-1', '誤付与取消'),
                // Usage自体(u1)へのCancelled/Confirmed等は一切発行されない。
            ]);
    }

    public function test_revoking_grant_then_reallocating_never_retargets_revoked_grant(): void
    {
        PaidLeaveAccountAggregate::fake(self::USER)
            ->given([
                new PaidLeaveGrantCreated('g1', '2024-04-01', '2027-04-01', 3.0, null, 'manual'),
                new PaidLeaveUsageDesignated('u1', 'wf-1', 'day-1', '2025-05-01', 3.0),
                new PaidLeaveUsageConfirmed('u1', 'approver-1'),
                new PaidLeaveUsageAllocated('u1', 'g1', 3.0),
                new PaidLeaveUsageAllocationReleased('u1', 'g1', 3.0),
                new PaidLeaveGrantRevoked('g1', 'admin-1', '誤付与取消'),
            ])
            ->when(function (PaidLeaveAccountAggregate $aggregate) {
                // 新たにg2を付与しても、取消済みg1は候補に入らずg2へ充当される。
                $aggregate->grant('g2', '2025-06-01', '2027-06-01', 5.0, null, 'manual');
            })
            ->assertRecorded([
                new PaidLeaveGrantCreated('g2', '2025-06-01', '2027-06-01', 5.0, null, 'manual'),
                new PaidLeaveUsageAllocated('u1', 'g2', 3.0),
            ]);
    }
}
