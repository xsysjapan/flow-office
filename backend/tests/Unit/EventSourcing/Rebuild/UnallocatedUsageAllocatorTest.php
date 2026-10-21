<?php

namespace Tests\Unit\EventSourcing\Rebuild;

use App\Domain\EventSourcing\Rebuild\EventStoreRebuilder;
use App\Domain\EventSourcing\Rebuild\RebuiltEvent;
use App\Domain\EventSourcing\Rebuild\StoredEventRow;
use App\Domain\EventSourcing\Rebuild\UnallocatedUsageAllocator;
use App\Domain\PaidLeaveAccount\Aggregates\PaidLeaveAccountAggregate;
use PHPUnit\Framework\TestCase;

/**
 * 作り直し後のイベントに、現行の充当の2つの規則(論点19 決定10-1)を発生順に当てはめる規則。
 * - 確定時の充当(confirm): 確定の時点の付与状態に AllocationPlanner を当てる(既存の充当を持つ消化記録は対象外)。
 * - 付与時の充当(grant): 付与の時点で、確定済み・未取消で不足している消化記録へ、付与の残りの範囲で充当する。
 */
class UnallocatedUsageAllocatorTest extends TestCase
{
    private const USER = 'u1';

    private UnallocatedUsageAllocator $allocator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allocator = new UnallocatedUsageAllocator;
    }

    public function test_usage_confirmed_before_the_grant_is_allocated_right_after_the_grant_created(): void
    {
        $grant = $this->grant(9, 0, 'G', '2026-10-01', '2028-09-30', 5.0);
        // 対象日(2026-07-10)は付与日より前・有効期間の外でも充当する。
        $result = $this->allocator->allocate([$grant, ...$this->usage('U1', '2026-07-10', 1.0, 3)]);

        $this->assertCount(1, $result['events']);
        $event = $result['events'][0];
        $this->assertSame('paid_leave_account.usage_allocated', $event->eventClass);
        $this->assertSame(self::USER, $event->aggregateUuid);
        $this->assertSame(['usageId' => 'U1', 'grantId' => 'G', 'allocatedDays' => 1.0], $event->payload);
        $this->assertSame(9, $event->anchorId);
        $this->assertSame(UnallocatedUsageAllocator::SUB_ORDER_BASE, $event->subOrder);
        $this->assertSame($grant->createdAt, $event->createdAt);
        $this->assertSame(1, $result['report']['allocated_usages']);
        $this->assertSame(0, $result['report']['unallocated_usages']);
        $this->assertSame([['grant_id' => 'G', 'days' => 1.0, 'rule' => 'grant']], $result['report']['usages'][0]['allocations']);
    }

    public function test_usage_confirmed_after_the_grant_is_allocated_right_after_the_confirmation(): void
    {
        $grant = $this->grant(1, 0, 'G', '2026-04-01', '2027-03-31', 5.0);
        $result = $this->allocator->allocate([$grant, ...$this->usage('U1', '2026-07-10', 1.0, 5)]);

        $this->assertCount(1, $result['events']);
        $event = $result['events'][0];
        $this->assertSame(['usageId' => 'U1', 'grantId' => 'G', 'allocatedDays' => 1.0], $event->payload);
        // 確定(anchor 5)の直後に置く。発生日時は確定から引き継ぐ(付与の日時ではない)。
        $this->assertSame(5, $event->anchorId);
        $this->assertSame(UnallocatedUsageAllocator::SUB_ORDER_BASE, $event->subOrder);
        $this->assertSame('2026-07-12 10:00:00', $event->createdAt);
        $this->assertSame(1, $result['report']['allocated_usages']);
        $this->assertSame(0, $result['report']['unallocated_usages']);
        $this->assertSame([['grant_id' => 'G', 'days' => 1.0, 'rule' => 'confirm']], $result['report']['usages'][0]['allocations']);
    }

    public function test_confirmation_allocation_takes_the_meta_of_the_confirmed_event_without_cutover(): void
    {
        $grant = $this->grant(1, 0, 'G', '2026-04-01', '2027-03-31', 5.0);
        $designated = $this->event('paid_leave_account.usage_designated', ['usageId' => 'U1', 'usedOn' => '2026-07-10', 'usedDays' => 1.0], 4, 0, '2026-07-12 10:00:00');
        $confirmed = new RebuiltEvent('paid_leave_account.usage_confirmed', self::USER, ['usageId' => 'U1', 'confirmedByUserId' => 'a1'], ['cutover' => ['mode' => 'carry'], 'source' => 'legacy'], '2026-07-12 10:00:00', 5, 1, null, null);
        $result = $this->allocator->allocate([$grant, $designated, $confirmed]);

        $this->assertCount(1, $result['events']);
        $this->assertSame(['source' => 'legacy'], $result['events'][0]->meta);
    }

    public function test_usage_with_an_existing_allocation_is_not_allocated_again_at_the_confirmation(): void
    {
        // 既存の充当(1日)があるので、確定時の導出はしない。残りの2日は、後の付与が無ければ未充当のまま。
        // (4日の空きがある G へ確定時に導出すると二重充当になる。)
        $grant = $this->grant(1, 0, 'G', '2026-04-01', '2027-03-31', 5.0);
        $designated = $this->event('paid_leave_account.usage_designated', ['usageId' => 'U1', 'usedOn' => '2026-07-10', 'usedDays' => 3.0], 4, 0, '2026-07-12 10:00:00');
        $confirmed = $this->event('paid_leave_account.usage_confirmed', ['usageId' => 'U1', 'confirmedByUserId' => 'a1'], 5, 1, '2026-07-12 10:00:00');
        $existing = $this->event('paid_leave_account.usage_allocated', ['usageId' => 'U1', 'grantId' => 'G', 'allocatedDays' => 1.0], 5, 2, '2026-07-12 10:00:00');
        $result = $this->allocator->allocate([$grant, $designated, $confirmed, $existing]);

        $this->assertSame([], $result['events']);
        $this->assertSame(1, $result['report']['unallocated_usages']);
        $this->assertEqualsWithDelta(2.0, $result['report']['unallocated_days'], 0.000001);
        $this->assertSame('付与の残り不足', $result['report']['unallocated'][0]['reason']);
    }

    public function test_confirmation_does_not_allocate_from_a_grant_that_has_expired_before_the_used_day(): void
    {
        // 対象日(2026-07-10)に有効でない付与(期限切れ)には、確定時の充当をしない(AllocationPlanner の規則どおり)。
        $grant = $this->grant(1, 0, 'G', '2025-04-01', '2026-03-31', 5.0);
        $result = $this->allocator->allocate([$grant, ...$this->usage('U1', '2026-07-10', 1.0, 5)]);

        $this->assertSame([], $result['events']);
        $this->assertSame(1, $result['report']['unallocated_usages']);
        $this->assertSame('付与の残り不足', $result['report']['unallocated'][0]['reason']);
    }

    public function test_confirmation_allocates_to_the_nearest_future_grant_as_the_planner_does(): void
    {
        // AllocationPlanner の Step 2: 対象日より後に付与日がある付与の残りへ、確定時に充当する。
        $grant = $this->grant(1, 0, 'G', '2026-10-01', '2028-09-30', 5.0);
        $result = $this->allocator->allocate([$grant, ...$this->usage('U1', '2026-07-10', 1.0, 5)]);

        $this->assertSame([['usageId' => 'U1', 'grantId' => 'G', 'allocatedDays' => 1.0]], array_map(fn (RebuiltEvent $e) => $e->payload, $result['events']));
        $this->assertSame(0, $result['report']['unallocated_usages']);
    }

    public function test_confirmation_uses_the_grant_expiry_as_changed_before_the_confirmation(): void
    {
        // 確定の時点の期限(延長後)で有効かどうかを判定する。
        $grant = $this->grant(1, 0, 'G', '2026-04-01', '2026-06-30', 5.0);
        $extended = $this->event('paid_leave_account.grant_expiry_changed', ['grantId' => 'G', 'newExpiresOn' => '2027-03-31', 'reason' => null, 'changedByUserId' => 'a1'], 2, 0, '2026-06-20 09:00:00');
        $result = $this->allocator->allocate([$grant, $extended, ...$this->usage('U1', '2026-07-10', 1.0, 5)]);

        $this->assertSame([['usageId' => 'U1', 'grantId' => 'G', 'allocatedDays' => 1.0]], array_map(fn (RebuiltEvent $e) => $e->payload, $result['events']));
    }

    public function test_shortfall_at_the_confirmation_is_allocated_by_a_later_grant_for_the_rest(): void
    {
        // 確定時は G1 の残り1日だけ充当できる。残りの2日は、後の付与 G2 の時点で充当する(付与時の規則)。
        $g1 = $this->grant(1, 0, 'G1', '2026-04-01', '2027-03-31', 1.0);
        $usage = $this->usage('U1', '2026-07-10', 3.0, 5);
        $g2 = $this->grant(9, 0, 'G2', '2026-10-01', '2028-09-30', 5.0);
        $result = $this->allocator->allocate([$g1, ...$usage, $g2]);

        $this->assertSame([
            ['usageId' => 'U1', 'grantId' => 'G1', 'allocatedDays' => 1.0],
            ['usageId' => 'U1', 'grantId' => 'G2', 'allocatedDays' => 2.0],
        ], array_map(fn (RebuiltEvent $e) => $e->payload, $result['events']));
        $this->assertSame([5, 9], array_map(fn (RebuiltEvent $e) => $e->anchorId, $result['events']));
        $this->assertSame(0, $result['report']['unallocated_usages']);
        $this->assertSame([
            ['grant_id' => 'G1', 'days' => 1.0, 'rule' => 'confirm'],
            ['grant_id' => 'G2', 'days' => 2.0, 'rule' => 'grant'],
        ], $result['report']['usages'][0]['allocations']);
    }

    public function test_oldest_confirmed_first_and_the_grant_remaining_is_respected(): void
    {
        $grant = $this->grant(9, 0, 'G', '2026-10-01', '2028-09-30', 3.0);
        $result = $this->allocator->allocate([
            ...$this->usage('LATE', '2026-07-10', 2.0, 4),
            ...$this->usage('EARLY', '2026-07-20', 2.0, 3),
            $grant,
        ]);

        $payloads = array_map(fn (RebuiltEvent $e) => $e->payload, $result['events']);
        $this->assertSame([
            ['usageId' => 'EARLY', 'grantId' => 'G', 'allocatedDays' => 2.0],
            ['usageId' => 'LATE', 'grantId' => 'G', 'allocatedDays' => 1.0],
        ], $payloads);
        $subs = array_map(fn (RebuiltEvent $e) => $e->subOrder, $result['events']);
        $this->assertSame(count($subs), count(array_unique($subs)));
        $this->assertSame(1, $result['report']['unallocated_usages']);
        $this->assertEqualsWithDelta(1.0, $result['report']['unallocated_days'], 0.000001);
        $this->assertSame('LATE', $result['report']['unallocated'][0]['usage_id']);
    }

    public function test_remaining_of_the_first_grant_overflows_to_the_next_grant_in_order(): void
    {
        $g1 = $this->grant(9, 0, 'G1', '2026-09-01', '2028-08-31', 1.0);
        $g2 = $this->grant(12, 0, 'G2', '2026-10-01', '2028-09-30', 5.0);
        $result = $this->allocator->allocate([...$this->usage('U1', '2026-07-10', 2.0, 3), $g1, $g2]);

        $this->assertSame([
            ['usageId' => 'U1', 'grantId' => 'G1', 'allocatedDays' => 1.0],
            ['usageId' => 'U1', 'grantId' => 'G2', 'allocatedDays' => 1.0],
        ], array_map(fn (RebuiltEvent $e) => $e->payload, $result['events']));
        $this->assertSame([9, 12], array_map(fn (RebuiltEvent $e) => $e->anchorId, $result['events']));
        $this->assertSame(0, $result['report']['unallocated_usages']);
    }

    public function test_already_allocated_and_cancelled_usages_are_out_of_scope(): void
    {
        $grant = $this->grant(9, 0, 'G', '2026-10-01', '2028-09-30', 5.0);
        $allocated = $this->usage('U2', '2026-07-10', 1.0, 3, allocated: true);
        $cancelled = $this->usage('U3', '2026-07-10', 1.0, 4, cancelled: true);
        $result = $this->allocator->allocate([...$allocated, ...$cancelled, $grant]);

        $this->assertSame([], $result['events']);
        $this->assertSame([], $result['report']['usages']);
    }

    public function test_existing_allocations_are_reflected_in_the_grant_remaining_and_the_shortfall(): void
    {
        $g1 = $this->grant(1, 0, 'G1', '2026-04-01', '2027-03-31', 5.0);
        // U2 は G1 に 4日 充当済み(G1 の残りは1日)。U1 は G2 の付与より前に確定するので、確定時に G1 の残り1日へ充当し、
        // 残り2日を G2(1日だけ)の付与時に充当する。1日は未充当のまま。
        $existing = $this->usage('U2', '2026-05-10', 4.0, 2, allocated: true, allocatedGrant: 'G1');
        $u1 = $this->usage('U1', '2026-07-10', 3.0, 3);
        $g2 = $this->grant(9, 0, 'G2', '2026-10-01', '2028-09-30', 1.0);
        $result = $this->allocator->allocate([$g1, ...$existing, ...$u1, $g2]);

        $this->assertSame([
            ['usageId' => 'U1', 'grantId' => 'G1', 'allocatedDays' => 1.0],
            ['usageId' => 'U1', 'grantId' => 'G2', 'allocatedDays' => 1.0],
        ], array_map(fn (RebuiltEvent $e) => $e->payload, $result['events']));
        $this->assertEqualsWithDelta(1.0, $result['report']['unallocated_days'], 0.000001);
    }

    public function test_released_allocation_returns_to_the_grant_remaining_for_a_usage_confirmed_later(): void
    {
        $g1 = $this->grant(1, 0, 'G1', '2026-04-01', '2027-03-31', 5.0);
        $other = $this->usage('U2', '2026-05-10', 4.0, 2, allocated: true, allocatedGrant: 'G1');
        $released = $this->event('paid_leave_account.usage_allocation_released', ['usageId' => 'U2', 'grantId' => 'G1', 'releasedDays' => 4.0], 8, 0, '2026-08-01 09:00:00');
        $cancel = $this->event('paid_leave_account.usage_cancelled', ['usageId' => 'U2', 'cancelledByUserId' => 'a1', 'reason' => null], 8, 1, '2026-08-01 09:00:00');
        // U1 は U2 の解除(anchor 8)より後に確定する。G1 の残りは5日なので、確定時に3日を G1 へ充当する。
        $u1 = $this->usage('U1', '2026-07-10', 3.0, 10);
        $result = $this->allocator->allocate([$g1, ...$other, $released, $cancel, ...$u1]);

        $this->assertSame([['usageId' => 'U1', 'grantId' => 'G1', 'allocatedDays' => 3.0]], array_map(fn (RebuiltEvent $e) => $e->payload, $result['events']));
        $this->assertSame(10, $result['events'][0]->anchorId);
        $this->assertSame(0, $result['report']['unallocated_usages']);
    }

    public function test_increase_of_the_grant_amount_reallocates_the_remaining_of_a_pending_usage(): void
    {
        // 付与時点は1日だけ充当(残り2日は未充当)。増額(anchor 10)の後、現行の allocateUnallocatedUsages と同じく残りを充当する(rule grant_changed)。
        $grant = $this->grant(9, 0, 'G', '2026-10-01', '2028-09-30', 1.0);
        $changed = $this->event('paid_leave_account.grant_amount_changed', ['grantId' => 'G', 'newGrantedDays' => 5.0, 'reason' => null, 'changedByUserId' => 'a1'], 10, 0, '2026-10-02 09:00:00');
        $result = $this->allocator->allocate([...$this->usage('U1', '2026-07-10', 3.0, 3), $grant, $changed]);

        $this->assertSame([
            ['usageId' => 'U1', 'grantId' => 'G', 'allocatedDays' => 1.0],
            ['usageId' => 'U1', 'grantId' => 'G', 'allocatedDays' => 2.0],
        ], array_map(fn (RebuiltEvent $e) => $e->payload, $result['events']));
        $this->assertSame([10, 100], [$result['events'][1]->anchorId, $result['events'][1]->subOrder]);
        $this->assertSame(0, $result['report']['unallocated_usages']);
        $this->assertSame([
            ['grant_id' => 'G', 'days' => 1.0, 'rule' => 'grant'],
            ['grant_id' => 'G', 'days' => 2.0, 'rule' => 'grant_changed'],
        ], $result['report']['usages'][0]['allocations']);
    }

    public function test_extension_of_the_expiry_reallocates_a_pending_usage_of_the_expired_grant(): void
    {
        // 対象日(2026-07-10)には期限切れの付与は確定時に充当されない。期限の延長(anchor 6)で有効になり、再充当する(rule grant_changed)。
        $grant = $this->grant(1, 0, 'G', '2026-04-01', '2026-06-30', 2.0);
        $extended = $this->event('paid_leave_account.grant_expiry_changed', ['grantId' => 'G', 'newExpiresOn' => '2027-03-31', 'reason' => null, 'changedByUserId' => 'a1'], 6, 0, '2026-06-20 09:00:00');
        $result = $this->allocator->allocate([$grant, ...$this->usage('U1', '2026-07-10', 1.0, 5), $extended]);

        $this->assertSame([['usageId' => 'U1', 'grantId' => 'G', 'allocatedDays' => 1.0]], array_map(fn (RebuiltEvent $e) => $e->payload, $result['events']));
        $this->assertSame([6, 100], [$result['events'][0]->anchorId, $result['events'][0]->subOrder]);
        $this->assertSame([['grant_id' => 'G', 'days' => 1.0, 'rule' => 'grant_changed']], $result['report']['usages'][0]['allocations']);
    }

    public function test_change_of_the_grant_date_reallocates_a_pending_usage_that_was_skipped_by_the_nearest_future_grant(): void
    {
        // 合成入力の検証: 確定時、対象日より後の最も近い付与(G_near)は U0 が使い切っており、G_far は対象外(Step 2 は最も近い1件のみ)。
        // G_far の付与日を対象日より前へ変えると、残りを充当する(rule grant_changed)。
        // 注: 集約の changeGrantDate は前の付与より後の日付しか許さないため、実際の履歴ではこの並びは起きにくい。規則の確認用。
        $near = $this->grant(1, 0, 'G_near', '2026-08-01', '2028-08-31', 1.0);
        $u0 = $this->usage('U0', '2026-07-10', 1.0, 3);
        $far = $this->grant(4, 0, 'G_far', '2026-09-01', '2028-08-31', 2.0);
        $u1 = $this->usage('U1', '2026-07-10', 2.0, 5);
        $dateChanged = $this->event('paid_leave_account.grant_date_changed', ['grantId' => 'G_far', 'newGrantedOn' => '2026-06-01', 'reason' => null, 'changedByUserId' => 'a1'], 6, 0, '2026-06-20 09:00:00');
        $result = $this->allocator->allocate([$near, ...$u0, $far, ...$u1, $dateChanged]);

        $this->assertSame([
            ['usageId' => 'U0', 'grantId' => 'G_near', 'allocatedDays' => 1.0],
            ['usageId' => 'U1', 'grantId' => 'G_far', 'allocatedDays' => 2.0],
        ], array_map(fn (RebuiltEvent $e) => $e->payload, $result['events']));
        $this->assertSame(0, $result['report']['unallocated_usages']);
    }

    public function test_revocation_releases_the_grant_allocation_before_the_revocation_and_reallocates_to_the_remaining_grant(): void
    {
        // G_a は対象日より後の最も近い付与で、U0 が確定時に使い切る。U1 は確定時に充当されず未充当。
        // G_a の取消で U0 の充当を取消の前に解除し、未充当の U0・U1 を、取消後に残る最も近い付与 G_b へ再充当する(rule grant_revoked)。
        $ga = $this->grant(1, 0, 'G_a', '2026-08-01', '2028-08-31', 1.0);
        $u0 = $this->usage('U0', '2026-07-10', 1.0, 3);
        $gb = $this->grant(2, 0, 'G_b', '2026-09-01', '2028-08-31', 5.0);
        $u1 = $this->usage('U1', '2026-07-10', 2.0, 5);
        $revoked = $this->event('paid_leave_account.grant_revoked', ['grantId' => 'G_a', 'revokedByUserId' => 'a1', 'reason' => null], 8, 0, '2026-08-01 09:00:00');
        $input = [$ga, ...$u0, $gb, ...$u1, $revoked];
        $result = $this->allocator->allocate($input);

        $this->assertSame([
            ['usageId' => 'U0', 'grantId' => 'G_a', 'allocatedDays' => 1.0],
            ['usageId' => 'U0', 'grantId' => 'G_a', 'releasedDays' => 1.0],
            ['usageId' => 'U0', 'grantId' => 'G_b', 'allocatedDays' => 1.0],
            ['usageId' => 'U1', 'grantId' => 'G_b', 'allocatedDays' => 2.0],
        ], array_map(fn (RebuiltEvent $e) => $e->payload, $result['events']));
        // 解除は取消の行より前、再充当は取消の後に置く。
        $this->assertLessThan(0, $result['events'][1]->subOrder);
        $this->assertSame(8, $result['events'][1]->anchorId);
        $this->assertSame(['grant_id' => 'G_b', 'days' => 1.0, 'rule' => 'grant_revoked'], $result['report']['usages'][0]['allocations'][1]);
        $this->assertSame(0.0, $this->replayGrantAllocated([...$input, ...$result['events']])['G_a']);
        $this->assertEqualsWithDelta(3.0, $this->replayGrantAllocated([...$input, ...$result['events']])['G_b'], 0.000001);
    }

    public function test_revocation_does_not_release_again_what_a_later_existing_release_already_released(): void
    {
        $grant = $this->grant(1, 0, 'G', '2026-04-01', '2027-03-31', 5.0);
        $revoked = $this->event('paid_leave_account.grant_revoked', ['grantId' => 'G', 'revokedByUserId' => 'a1', 'reason' => null], 6, 0, '2026-08-01 09:00:00');
        $laterRelease = $this->event('paid_leave_account.usage_allocation_released', ['usageId' => 'U1', 'grantId' => 'G', 'releasedDays' => 1.0], 7, 0, '2026-08-01 09:00:00');
        $result = $this->allocator->allocate([$grant, ...$this->usage('U1', '2026-07-10', 1.0, 5), $revoked, $laterRelease]);

        $this->assertSame(['paid_leave_account.usage_allocated'], array_map(fn (RebuiltEvent $e) => $e->eventClass, $result['events']));
    }

    public function test_user_without_any_grant_stays_unallocated_and_other_users_are_independent(): void
    {
        $noGrant = $this->usage('U1', '2026-07-10', 1.0, 3);
        $grant = new RebuiltEvent('paid_leave_account.grant_created', 'other', [
            'grantId' => 'G', 'grantedOn' => '2026-10-01', 'expiresOn' => '2028-09-30', 'grantedDays' => 10.0, 'grantReason' => null, 'source' => 'auto',
        ], [], '2026-10-01 00:00:00', 9, 0, null, null);
        $result = $this->allocator->allocate([...$noGrant, $grant]);

        $this->assertSame([], $result['events']);
        $this->assertSame(1, $result['report']['unallocated_usages']);
        $this->assertSame('付与なし', $result['report']['unallocated'][0]['reason']);
        $this->assertSame('U1', $result['report']['usages'][0]['usage_id']);
        $this->assertSame([], $result['report']['usages'][0]['allocations']);
    }

    public function test_revocation_releases_the_grant_allocation_before_the_revocation(): void
    {
        // 付与時点で1日充当(grant)。取消(anchor 10)の前に、その充当を解除する(現行の revokeGrant と同じ)。
        $grant = $this->grant(9, 0, 'G', '2026-10-01', '2028-09-30', 5.0);
        $revoked = $this->event('paid_leave_account.grant_revoked', ['grantId' => 'G', 'revokedByUserId' => 'a1', 'reason' => null], 10, 0, '2026-10-02 09:00:00');
        $input = [...$this->usage('U1', '2026-07-10', 1.0, 3), $grant, $revoked];
        $result = $this->allocator->allocate($input);

        $this->assertSame(['paid_leave_account.usage_allocated', 'paid_leave_account.usage_allocation_released'], array_map(fn (RebuiltEvent $e) => $e->eventClass, $result['events']));
        $this->assertSame(['usageId' => 'U1', 'grantId' => 'G', 'releasedDays' => 1.0], $result['events'][1]->payload);
        $this->assertSame(10, $result['events'][1]->anchorId);
        $this->assertLessThan(0, $result['events'][1]->subOrder);
        $this->assertEqualsWithDelta(0.0, $this->replayGrantAllocated([...$input, ...$result['events']])['G'], 0.000001);
    }

    public function test_rebuilder_allocates_usage_requested_and_approved_before_the_cutover_grant_and_reports_it(): void
    {
        $at = '2026-07-20 10:00:00';
        $result = (new EventStoreRebuilder)->rebuild([
            new StoredEventRow(1, 'R1', 1, 'paid_leave.requested', ['userId' => self::USER, 'targetDate' => '2026-07-21', 'leaveType' => 'full', 'hours' => null, 'requestedDays' => 1.0, 'approverUserId' => 'a1', 'reason' => null, 'requestGroupId' => null], [], $at),
            new StoredEventRow(2, 'R1', 2, 'paid_leave.request_approved', ['approvedByUserId' => 'a1'], [], $at),
            new StoredEventRow(3, self::USER, 1, 'paid_leave_account.migrated', [
                'cutoverDate' => '2026-08-01',
                'grants' => [
                    ['grantId' => 'G2', 'originalGrantedOn' => null, 'originalGrantedDays' => 5.0, 'remainingDaysAtCutover' => 5.0, 'expiresOn' => '2027-03-31', 'source' => 'migration', 'cutoverMetadata' => ['mode' => 'carry', 'notes' => null]],
                ],
            ], [], '2026-08-01 09:00:00'),
        ]);

        $this->assertTrue($result->ok, json_encode($result->unsupported));
        $this->assertSame(
            [
                'paid_leave_request.requested',
                'paid_leave_account.usage_designated',
                'paid_leave_request.approved',
                'paid_leave_account.usage_confirmed',
                'paid_leave_account.grant_created',
                'paid_leave_account.usage_allocated',
            ],
            array_column($result->rows, 'event_class'),
        );
        $allocated = $result->rows[5];
        $this->assertSame('G2', $allocated['event_properties']['grantId']);
        $this->assertEqualsWithDelta(1.0, $allocated['event_properties']['allocatedDays'], 0.000001);
        // 充当は途中の工程では社員IDで書かれ、最後の工程で新しいストリームIDへ付け替わる(userId が入る)。
        $accountStream = PaidLeaveAccountAggregate::streamIdFor(self::USER);
        $this->assertSame($accountStream, $allocated['aggregate_uuid']);
        $this->assertSame(self::USER, $allocated['event_properties']['userId']);
        $this->assertSame([1, 2, 3, 4], array_column(array_filter($result->rows, fn (array $r) => $r['aggregate_uuid'] === $accountStream), 'aggregate_version'));
        $this->assertSame(1, $result->report['usage_allocation']['allocated_usages']);
        $this->assertSame(0, $result->report['usage_allocation']['unallocated_usages']);
    }

    public function test_rebuilder_leaves_usage_without_any_grant_unallocated_and_reports_it(): void
    {
        $at = '2026-07-20 10:00:00';
        $result = (new EventStoreRebuilder)->rebuild([
            new StoredEventRow(1, 'R1', 1, 'paid_leave.requested', ['userId' => self::USER, 'targetDate' => '2026-07-21', 'leaveType' => 'full', 'hours' => null, 'requestedDays' => 1.0, 'approverUserId' => 'a1', 'reason' => null, 'requestGroupId' => null], [], $at),
            new StoredEventRow(2, 'R1', 2, 'paid_leave.request_approved', ['approvedByUserId' => 'a1'], [], $at),
        ]);

        $this->assertTrue($result->ok, json_encode($result->unsupported));
        $this->assertNotContains('paid_leave_account.usage_allocated', array_column($result->rows, 'event_class'));
        $this->assertSame(1, $result->report['usage_allocation']['unallocated_usages']);
        $this->assertSame('付与なし', $result->report['usage_allocation']['unallocated'][0]['reason']);
    }

    public function test_cancel_releases_the_derived_allocation_before_the_cancel_and_the_grant_is_restored(): void
    {
        $grant = $this->grant(1, 0, 'G', '2026-04-01', '2027-03-31', 5.0);
        $cancel = $this->event('paid_leave_account.usage_cancelled', ['usageId' => 'U1', 'cancelledByUserId' => 'a1', 'reason' => null], 6, 0, '2026-08-01 09:00:00');
        $input = [$grant, ...$this->usage('U1', '2026-07-10', 1.0, 5), $cancel];
        $result = $this->allocator->allocate($input);

        $this->assertSame(['paid_leave_account.usage_allocated', 'paid_leave_account.usage_allocation_released'], array_map(fn (RebuiltEvent $e) => $e->eventClass, $result['events']));
        $release = $result['events'][1];
        $this->assertSame(['usageId' => 'U1', 'grantId' => 'G', 'releasedDays' => 1.0], $release->payload);
        $this->assertSame(6, $release->anchorId);
        $this->assertLessThan(0, $release->subOrder);
        $this->assertSame('2026-08-01 09:00:00', $release->createdAt);

        // 並び順(anchor・subOrder)で積み上げると、付与の充当は0に戻る。取消の行より前に解除が並ぶ。
        $sorted = $this->sortedAll([...$input, ...$result['events']]);
        $this->assertLessThan(array_search($cancel, $sorted, true), array_search($release, $sorted, true));
        $this->assertEqualsWithDelta(0.0, $this->replayGrantAllocated([...$input, ...$result['events']])['G'], 0.000001);
        $this->assertSame([], $result['report']['usages']);
    }

    public function test_cancel_frees_the_grant_for_another_unallocated_usage_after_the_cancel(): void
    {
        // G1(1日)を U1 が確定時に使い切る。U2 は確定時に充当されない。U1 の取消で G1 が空き、U2 へ取消の後に再充当する。
        $g1 = $this->grant(1, 0, 'G1', '2026-04-01', '2027-03-31', 1.0);
        $cancel = $this->event('paid_leave_account.usage_cancelled', ['usageId' => 'U1', 'cancelledByUserId' => 'a1', 'reason' => null], 8, 0, '2026-08-01 09:00:00');
        $input = [$g1, ...$this->usage('U1', '2026-07-10', 3.0, 5), ...$this->usage('U2', '2026-07-20', 2.0, 7), $cancel];
        $result = $this->allocator->allocate($input);

        $this->assertSame(['paid_leave_account.usage_allocated', 'paid_leave_account.usage_allocation_released', 'paid_leave_account.usage_allocated'], array_map(fn (RebuiltEvent $e) => $e->eventClass, $result['events']));
        $this->assertSame(['usageId' => 'U1', 'grantId' => 'G1', 'allocatedDays' => 1.0], $result['events'][0]->payload);
        $this->assertSame(['usageId' => 'U1', 'grantId' => 'G1', 'releasedDays' => 1.0], $result['events'][1]->payload);
        $this->assertSame(['usageId' => 'U2', 'grantId' => 'G1', 'allocatedDays' => 1.0], $result['events'][2]->payload);
        // 再充当は取消の後(同じ anchor で取消より後の subOrder)に置く。
        $realloc = $result['events'][2];
        $this->assertSame(8, $realloc->anchorId);
        $this->assertGreaterThan(0, $realloc->subOrder);
        $this->assertSame([['grant_id' => 'G1', 'days' => 1.0, 'rule' => 'cancel']], $result['report']['usages'][0]['allocations']);
        $this->assertSame(1, $result['report']['unallocated_usages']);
        $this->assertEqualsWithDelta(1.0, $result['report']['unallocated_days'], 0.000001);
        $this->assertEqualsWithDelta(1.0, $this->replayGrantAllocated([...$input, ...$result['events']])['G1'], 0.000001);
    }

    public function test_cancel_does_not_reallocate_a_usage_that_already_has_an_allocation(): void
    {
        // U2 は既存の充当(1日)を持つので、取消後の再充当の対象外。G の空き(2日)は U2 に導出しない。
        $g = $this->grant(1, 0, 'G', '2026-04-01', '2027-03-31', 3.0);
        $cancel = $this->event('paid_leave_account.usage_cancelled', ['usageId' => 'U1', 'cancelledByUserId' => 'a1', 'reason' => null], 8, 0, '2026-08-01 09:00:00');
        $u2Designated = $this->event('paid_leave_account.usage_designated', ['usageId' => 'U2', 'usedOn' => '2026-07-20', 'usedDays' => 2.0], 6, 0, '2026-07-12 10:00:00');
        $u2Confirmed = $this->event('paid_leave_account.usage_confirmed', ['usageId' => 'U2', 'confirmedByUserId' => 'a1'], 7, 1, '2026-07-12 10:00:00');
        $u2Existing = $this->event('paid_leave_account.usage_allocated', ['usageId' => 'U2', 'grantId' => 'G', 'allocatedDays' => 1.0], 7, 2, '2026-07-12 10:00:00');
        $input = [$g, ...$this->usage('U1', '2026-07-10', 2.0, 5), $u2Designated, $u2Confirmed, $u2Existing, $cancel];
        $result = $this->allocator->allocate($input);

        $this->assertSame(['paid_leave_account.usage_allocated', 'paid_leave_account.usage_allocation_released'], array_map(fn (RebuiltEvent $e) => $e->eventClass, $result['events']));
        // U2 は既存の充当の分だけ(1日)で、残りの1日は G に空きがあっても未充当のまま。
        $this->assertSame(1, $result['report']['unallocated_usages']);
        $this->assertEqualsWithDelta(1.0, $result['report']['unallocated_days'], 0.000001);
    }

    public function test_cancel_does_not_release_twice_when_an_existing_release_follows_the_cancel(): void
    {
        $grant = $this->grant(1, 0, 'G', '2026-04-01', '2027-03-31', 5.0);
        $cancel = $this->event('paid_leave_account.usage_cancelled', ['usageId' => 'U1', 'cancelledByUserId' => 'a1', 'reason' => null], 6, 0, '2026-08-01 09:00:00');
        $laterRelease = $this->event('paid_leave_account.usage_allocation_released', ['usageId' => 'U1', 'grantId' => 'G', 'releasedDays' => 1.0], 7, 0, '2026-08-01 09:00:00');
        $result = $this->allocator->allocate([$grant, ...$this->usage('U1', '2026-07-10', 1.0, 5), $cancel, $laterRelease]);

        // 導出したのは確定時の充当だけ。取消より後の既存の解除があるので、取消の解除は導出しない。
        $this->assertSame(['paid_leave_account.usage_allocated'], array_map(fn (RebuiltEvent $e) => $e->eventClass, $result['events']));
    }

    public function test_cancel_does_not_release_again_what_an_existing_release_before_the_cancel_already_released(): void
    {
        $grant = $this->grant(1, 0, 'G', '2026-04-01', '2027-03-31', 5.0);
        $release = $this->event('paid_leave_account.usage_allocation_released', ['usageId' => 'U1', 'grantId' => 'G', 'releasedDays' => 1.0], 6, 0, '2026-08-01 09:00:00');
        $cancel = $this->event('paid_leave_account.usage_cancelled', ['usageId' => 'U1', 'cancelledByUserId' => 'a1', 'reason' => null], 6, 1, '2026-08-01 09:00:00');
        $result = $this->allocator->allocate([$grant, ...$this->usage('U1', '2026-07-10', 1.0, 5), $release, $cancel]);

        $this->assertSame(['paid_leave_account.usage_allocated'], array_map(fn (RebuiltEvent $e) => $e->eventClass, $result['events']));
    }

    /**
     * 作り直し後の並び(anchor・subOrder・入力の順)に並べた全イベント。
     *
     * @param  list<RebuiltEvent>  $events
     * @return list<RebuiltEvent>
     */
    private function sortedAll(array $events): array
    {
        $indexed = [];
        foreach (array_values($events) as $i => $event) {
            $indexed[] = [$event, $i];
        }
        usort($indexed, fn (array $a, array $b) => [$a[0]->anchorId, $a[0]->subOrder, $a[1]] <=> [$b[0]->anchorId, $b[0]->subOrder, $b[1]]);

        return array_map(fn (array $pair) => $pair[0], $indexed);
    }

    /**
     * 並び順で積み上げた、付与IDごとの充当の合計(usage_allocated は加算、usage_allocation_released は減算)。
     *
     * @param  list<RebuiltEvent>  $events
     * @return array<string, float>
     */
    private function replayGrantAllocated(array $events): array
    {
        $sum = [];
        foreach ($this->sortedAll($events) as $event) {
            if ($event->eventClass === 'paid_leave_account.usage_allocated') {
                $sum[$event->payload['grantId']] = ($sum[$event->payload['grantId']] ?? 0.0) + (float) $event->payload['allocatedDays'];
            }
            if ($event->eventClass === 'paid_leave_account.usage_allocation_released') {
                $sum[$event->payload['grantId']] = ($sum[$event->payload['grantId']] ?? 0.0) - (float) $event->payload['releasedDays'];
            }
        }

        return $sum;
    }

    /**
     * 消化の指定と確定のイベント(確定は anchor $confirmAnchor)。
     *
     * @return list<RebuiltEvent> [designated, confirmed, ...(allocated), ...(cancelled)]
     */
    private function usage(string $usageId, string $usedOn, float $days, int $confirmAnchor, bool $allocated = false, bool $cancelled = false, string $allocatedGrant = 'X'): array
    {
        $at = '2026-07-12 10:00:00';
        $events = [
            $this->event('paid_leave_account.usage_designated', ['usageId' => $usageId, 'usedOn' => $usedOn, 'usedDays' => $days], $confirmAnchor - 1, 0, $at),
            $this->event('paid_leave_account.usage_confirmed', ['usageId' => $usageId, 'confirmedByUserId' => 'a1'], $confirmAnchor, 1, $at),
        ];
        if ($allocated) {
            $events[] = $this->event('paid_leave_account.usage_allocated', ['usageId' => $usageId, 'grantId' => $allocatedGrant, 'allocatedDays' => $days], $confirmAnchor, 2, $at);
        }
        if ($cancelled) {
            $events[] = $this->event('paid_leave_account.usage_cancelled', ['usageId' => $usageId, 'cancelledByUserId' => 'a1', 'reason' => null], $confirmAnchor, 3, $at);
        }

        return $events;
    }

    private function grant(int $anchor, int $sub, string $grantId, string $grantedOn, string $expiresOn, float $days): RebuiltEvent
    {
        return $this->event('paid_leave_account.grant_created', [
            'grantId' => $grantId, 'grantedOn' => $grantedOn, 'expiresOn' => $expiresOn, 'grantedDays' => $days, 'grantReason' => null, 'source' => 'auto',
        ], $anchor, $sub, '2026-08-01 09:00:00');
    }

    /** @param  array<string, mixed>  $payload */
    private function event(string $class, array $payload, int $anchor, int $sub, string $at): RebuiltEvent
    {
        return new RebuiltEvent($class, self::USER, $payload, [], $at, $anchor, $sub, null, null);
    }
}
