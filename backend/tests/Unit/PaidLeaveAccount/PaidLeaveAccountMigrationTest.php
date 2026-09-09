<?php

namespace Tests\Unit\PaidLeaveAccount;

use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\PaidLeaveAccount\Aggregates\PaidLeaveAccountAggregate;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveAccountMigrated;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveGrantCreated;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveUsageAllocated;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveUsageConfirmed;
use App\Domain\PaidLeaveAccount\Events\PaidLeaveUsageDesignated;
use Tests\TestCase;

/**
 * 最終Phase(データ移行)。`PaidLeaveAccountAggregate::migrateGrants()`の単体テスト
 * (docs/changesets/20260906-paid-leave-domain-redesign/spec.md「既存データ移行」参照)。
 * すべてEvent→Aggregate replay→Commandの結果を検証する形式で、Projection(Eloquent)は
 * 一切使わない。
 */
class PaidLeaveAccountMigrationTest extends TestCase
{
    private const USER = 'user-1';

    // ---- モードA: Grant単位で完全に分かる ----

    public function test_mode_a_full_grant_detail_is_recorded(): void
    {
        PaidLeaveAccountAggregate::fake(self::USER)
            ->when(function (PaidLeaveAccountAggregate $aggregate) {
                $aggregate->migrateGrants('2026-04-01', [
                    [
                        'grantId' => 'g1',
                        'originalGrantedOn' => '2025-04-01',
                        'originalGrantedDays' => 10.0,
                        'remainingDaysAtCutover' => 6.0,
                        'expiresOn' => '2027-04-01',
                        'source' => 'migration',
                        'cutoverMetadata' => ['mode' => 'A'],
                    ],
                ]);
            })
            ->assertRecorded([
                new PaidLeaveAccountMigrated('2026-04-01', [
                    [
                        'grantId' => 'g1',
                        'originalGrantedOn' => '2025-04-01',
                        'originalGrantedDays' => 10.0,
                        'remainingDaysAtCutover' => 6.0,
                        'expiresOn' => '2027-04-01',
                        'source' => 'migration',
                        'cutoverMetadata' => ['mode' => 'A'],
                    ],
                ]),
            ]);
    }

    public function test_mode_a_available_days_is_remaining_days_at_cutover_not_original(): void
    {
        // モードAでも、以後の消化上限はremainingDaysAtCutover(6.0)そのものであり、
        // originalGrantedDays(10.0)は付随情報に留める(過去Usage履歴の再現は必須ではない)。
        PaidLeaveAccountAggregate::fake(self::USER)
            ->given([
                new PaidLeaveAccountMigrated('2026-04-01', [
                    [
                        'grantId' => 'g1',
                        'originalGrantedOn' => '2025-04-01',
                        'originalGrantedDays' => 10.0,
                        'remainingDaysAtCutover' => 6.0,
                        'expiresOn' => '2027-04-01',
                        'source' => 'migration',
                        'cutoverMetadata' => ['mode' => 'A'],
                    ],
                ]),
            ])
            ->when(function (PaidLeaveAccountAggregate $aggregate) {
                $aggregate->designateUsage('u1', null, null, '2026-05-01', 6.0, 'full');
                $aggregate->confirmUsage('u1', null);
            })
            ->assertRecorded([
                new PaidLeaveUsageDesignated('u1', null, null, '2026-05-01', 6.0, 'full'),
                new PaidLeaveUsageConfirmed('u1', null),
                new PaidLeaveUsageAllocated('u1', 'g1', 6.0),
            ]);
    }

    public function test_mode_a_usage_exceeding_remaining_days_is_only_partially_allocated(): void
    {
        // 6.0しか残っていないGrantに8.0のUsageをconfirmしても、6.0しか充当されない
        // (originalGrantedDays=10.0は上限としては使われない)。
        PaidLeaveAccountAggregate::fake(self::USER)
            ->given([
                new PaidLeaveAccountMigrated('2026-04-01', [
                    [
                        'grantId' => 'g1',
                        'originalGrantedOn' => '2025-04-01',
                        'originalGrantedDays' => 10.0,
                        'remainingDaysAtCutover' => 6.0,
                        'expiresOn' => '2027-04-01',
                        'source' => 'migration',
                        'cutoverMetadata' => ['mode' => 'A'],
                    ],
                ]),
            ])
            ->when(function (PaidLeaveAccountAggregate $aggregate) {
                $aggregate->designateUsage('u1', null, null, '2026-05-01', 8.0, 'full');
                $aggregate->confirmUsage('u1', null);
            })
            ->assertRecorded([
                new PaidLeaveUsageDesignated('u1', null, null, '2026-05-01', 8.0, 'full'),
                new PaidLeaveUsageConfirmed('u1', null),
                new PaidLeaveUsageAllocated('u1', 'g1', 6.0),
            ]);
    }

    // ---- モードB: 前年度繰越+当年度残高 ----

    public function test_mode_b_two_grants_carryover_and_current_year(): void
    {
        PaidLeaveAccountAggregate::fake(self::USER)
            ->when(function (PaidLeaveAccountAggregate $aggregate) {
                $aggregate->migrateGrants('2026-04-01', [
                    [
                        'grantId' => 'carryover',
                        'originalGrantedOn' => '2024-04-01',
                        'originalGrantedDays' => null,
                        'remainingDaysAtCutover' => 3.0,
                        'expiresOn' => '2026-04-01',
                        'source' => 'migration',
                        'cutoverMetadata' => ['mode' => 'B'],
                    ],
                    [
                        'grantId' => 'current',
                        'originalGrantedOn' => '2025-04-01',
                        'originalGrantedDays' => 11.0,
                        'remainingDaysAtCutover' => 11.0,
                        'expiresOn' => '2027-04-01',
                        'source' => 'migration',
                        'cutoverMetadata' => ['mode' => 'B'],
                    ],
                ]);
            })
            ->assertRecorded([
                new PaidLeaveAccountMigrated('2026-04-01', [
                    [
                        'grantId' => 'carryover',
                        'originalGrantedOn' => '2024-04-01',
                        'originalGrantedDays' => null,
                        'remainingDaysAtCutover' => 3.0,
                        'expiresOn' => '2026-04-01',
                        'source' => 'migration',
                        'cutoverMetadata' => ['mode' => 'B'],
                    ],
                    [
                        'grantId' => 'current',
                        'originalGrantedOn' => '2025-04-01',
                        'originalGrantedDays' => 11.0,
                        'remainingDaysAtCutover' => 11.0,
                        'expiresOn' => '2027-04-01',
                        'source' => 'migration',
                        'cutoverMetadata' => ['mode' => 'B'],
                    ],
                ]),
            ]);
    }

    // ---- モードC: 残高と有効期限のみ ----

    public function test_mode_c_unknown_original_amount_is_not_fabricated(): void
    {
        // originalGrantedOn/originalGrantedDaysともにnullのまま記録される
        // (架空の「本来の付与日数」を補わない)。
        PaidLeaveAccountAggregate::fake(self::USER)
            ->when(function (PaidLeaveAccountAggregate $aggregate) {
                $aggregate->migrateGrants('2026-04-01', [
                    [
                        'grantId' => 'g1',
                        'originalGrantedOn' => null,
                        'originalGrantedDays' => null,
                        'remainingDaysAtCutover' => 4.0,
                        'expiresOn' => '2027-04-01',
                        'source' => 'migration',
                        'cutoverMetadata' => ['mode' => 'C'],
                    ],
                ]);
            })
            ->assertRecorded([
                new PaidLeaveAccountMigrated('2026-04-01', [
                    [
                        'grantId' => 'g1',
                        'originalGrantedOn' => null,
                        'originalGrantedDays' => null,
                        'remainingDaysAtCutover' => 4.0,
                        'expiresOn' => '2027-04-01',
                        'source' => 'migration',
                        'cutoverMetadata' => ['mode' => 'C'],
                    ],
                ]),
            ]);
    }

    public function test_mode_c_grant_caps_usage_at_remaining_days_with_no_phantom_ceiling(): void
    {
        PaidLeaveAccountAggregate::fake(self::USER)
            ->given([
                new PaidLeaveAccountMigrated('2026-04-01', [
                    [
                        'grantId' => 'g1',
                        'originalGrantedOn' => null,
                        'originalGrantedDays' => null,
                        'remainingDaysAtCutover' => 4.0,
                        'expiresOn' => '2027-04-01',
                        'source' => 'migration',
                        'cutoverMetadata' => ['mode' => 'C'],
                    ],
                ]),
            ])
            ->when(function (PaidLeaveAccountAggregate $aggregate) {
                $aggregate->designateUsage('u1', null, null, '2026-05-01', 4.0, 'full');
                $aggregate->confirmUsage('u1', null);
                // さらに1.0の追加Usageを試みても、g1に空きはもう無い(未充当のまま残る)。
                $aggregate->designateUsage('u2', null, null, '2026-05-02', 1.0, 'full');
                $aggregate->confirmUsage('u2', null);
            })
            ->assertRecorded([
                new PaidLeaveUsageDesignated('u1', null, null, '2026-05-01', 4.0, 'full'),
                new PaidLeaveUsageConfirmed('u1', null),
                new PaidLeaveUsageAllocated('u1', 'g1', 4.0),
                new PaidLeaveUsageDesignated('u2', null, null, '2026-05-02', 1.0, 'full'),
                new PaidLeaveUsageConfirmed('u2', null),
                // u2はg1に充当できず、未充当のまま(PaidLeaveUsageAllocatedは発行されない)。
            ]);
    }

    // ---- 「最新Grant」判定・以後の通常grant()との整合 ----

    public function test_migrated_grant_with_known_original_granted_on_is_treated_as_latest(): void
    {
        // モードA/Bのように付与日が分かっている場合、その日付が「最新Grant」判定の基準になる。
        // 移行後の通常grant()はこの日付より後でなければならない。
        $this->expectException(DomainRuleException::class);

        PaidLeaveAccountAggregate::fake(self::USER)
            ->given([
                new PaidLeaveAccountMigrated('2026-04-01', [
                    [
                        'grantId' => 'g1',
                        'originalGrantedOn' => '2025-04-01',
                        'originalGrantedDays' => 10.0,
                        'remainingDaysAtCutover' => 6.0,
                        'expiresOn' => '2027-04-01',
                        'source' => 'migration',
                        'cutoverMetadata' => ['mode' => 'A'],
                    ],
                ]),
            ])
            ->when(function (PaidLeaveAccountAggregate $aggregate) {
                // originalGrantedOn(2025-04-01)より前の日付なので拒否される。
                $aggregate->grant('g2', '2025-01-01', '2027-01-01', 10.0, null, 'manual');
            });
    }

    public function test_normal_grant_after_migration_succeeds_when_dated_after_migrated_grant(): void
    {
        PaidLeaveAccountAggregate::fake(self::USER)
            ->given([
                new PaidLeaveAccountMigrated('2026-04-01', [
                    [
                        'grantId' => 'g1',
                        'originalGrantedOn' => '2025-04-01',
                        'originalGrantedDays' => 10.0,
                        'remainingDaysAtCutover' => 6.0,
                        'expiresOn' => '2027-04-01',
                        'source' => 'migration',
                        'cutoverMetadata' => ['mode' => 'A'],
                    ],
                ]),
            ])
            ->when(function (PaidLeaveAccountAggregate $aggregate) {
                $aggregate->grant('g2', '2026-04-02', '2028-04-02', 11.0, null, 'manual');
            })
            ->assertRecorded([
                new PaidLeaveGrantCreated('g2', '2026-04-02', '2028-04-02', 11.0, null, 'manual'),
            ]);
    }

    public function test_mode_c_grant_effective_ordering_uses_cutover_date_when_original_granted_on_unknown(): void
    {
        // モードC(originalGrantedOn不明)は、順序付けの基準日としてcutoverDateを使う。
        $this->expectException(DomainRuleException::class);

        PaidLeaveAccountAggregate::fake(self::USER)
            ->given([
                new PaidLeaveAccountMigrated('2026-04-01', [
                    [
                        'grantId' => 'g1',
                        'originalGrantedOn' => null,
                        'originalGrantedDays' => null,
                        'remainingDaysAtCutover' => 4.0,
                        'expiresOn' => '2027-04-01',
                        'source' => 'migration',
                        'cutoverMetadata' => ['mode' => 'C'],
                    ],
                ]),
            ])
            ->when(function (PaidLeaveAccountAggregate $aggregate) {
                // cutoverDate(2026-04-01)と同日なので拒否される。
                $aggregate->grant('g2', '2026-04-01', '2028-04-01', 10.0, null, 'manual');
            });
    }

    // ---- 一度きり制約・入力バリデーション ----

    public function test_migration_is_rejected_when_account_already_has_a_grant(): void
    {
        $this->expectException(DomainRuleException::class);

        PaidLeaveAccountAggregate::fake(self::USER)
            ->given([
                new PaidLeaveGrantCreated('g1', '2025-04-01', '2027-04-01', 10.0, null, 'manual'),
            ])
            ->when(function (PaidLeaveAccountAggregate $aggregate) {
                $aggregate->migrateGrants('2026-04-01', [
                    [
                        'grantId' => 'g2',
                        'originalGrantedOn' => null,
                        'originalGrantedDays' => null,
                        'remainingDaysAtCutover' => 3.0,
                        'expiresOn' => '2027-04-01',
                        'source' => 'migration',
                        'cutoverMetadata' => null,
                    ],
                ]);
            });
    }

    public function test_migration_rejects_original_granted_days_less_than_remaining(): void
    {
        $this->expectException(DomainRuleException::class);

        PaidLeaveAccountAggregate::fake(self::USER)
            ->when(function (PaidLeaveAccountAggregate $aggregate) {
                $aggregate->migrateGrants('2026-04-01', [
                    [
                        'grantId' => 'g1',
                        'originalGrantedOn' => '2025-04-01',
                        'originalGrantedDays' => 3.0,
                        'remainingDaysAtCutover' => 6.0,
                        'expiresOn' => '2027-04-01',
                        'source' => 'migration',
                        'cutoverMetadata' => null,
                    ],
                ]);
            });
    }

    public function test_migration_rejects_duplicate_grant_ids(): void
    {
        $this->expectException(DomainRuleException::class);

        PaidLeaveAccountAggregate::fake(self::USER)
            ->when(function (PaidLeaveAccountAggregate $aggregate) {
                $aggregate->migrateGrants('2026-04-01', [
                    [
                        'grantId' => 'g1',
                        'originalGrantedOn' => '2025-04-01',
                        'originalGrantedDays' => null,
                        'remainingDaysAtCutover' => 1.0,
                        'expiresOn' => '2027-04-01',
                        'source' => 'migration',
                        'cutoverMetadata' => null,
                    ],
                    [
                        'grantId' => 'g1',
                        'originalGrantedOn' => '2025-05-01',
                        'originalGrantedDays' => null,
                        'remainingDaysAtCutover' => 1.0,
                        'expiresOn' => '2027-04-01',
                        'source' => 'migration',
                        'cutoverMetadata' => null,
                    ],
                ]);
            });
    }
}
