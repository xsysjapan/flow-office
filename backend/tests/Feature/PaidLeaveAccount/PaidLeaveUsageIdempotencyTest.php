<?php

namespace Tests\Feature\PaidLeaveAccount;

use App\Domain\EventSourcing\CommandBus;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use App\Domain\PaidLeaveAccount\Aggregates\PaidLeaveAccountAggregate;
use App\Domain\PaidLeaveAccount\Commands\CancelPaidLeaveUsage;
use App\Domain\PaidLeaveAccount\Commands\ConfirmPaidLeaveUsage;
use App\Domain\PaidLeaveAccount\Commands\DesignatePaidLeaveUsage;
use App\Domain\PaidLeaveAccount\Commands\GrantPaidLeave;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

/**
 * 有給の消化記録のCommand(Designate/Confirm/Cancel)のHandler単位の冪等性と、
 * paidLeaveRequestId指定による対象特定を、CommandBus経由で検証する。
 * viaReactor=trueは既に目的の状態なら何もせず、viaReactor=falseは従来どおり状態不正を例外にする
 * (docs/changesets/20261009-keep-leave-work-type-on-edit/spec.md 仕様確定事項A)。
 */
class PaidLeaveUsageIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private function bus(): CommandBus
    {
        return app(CommandBus::class);
    }

    private function grantFor(User $user): void
    {
        $this->bus()->dispatch(new GrantPaidLeave($user->id, '2025-07-01', '2027-06-30', 10.0, null));
    }

    private function designate(User $user, string $paidLeaveRequestId, bool $viaReactor = false, float $usedDays = 1.0): string
    {
        return $this->bus()->dispatch(new DesignatePaidLeaveUsage(
            userId: $user->id,
            workflowRequestId: null,
            attendanceDayId: null,
            usedOn: '2026-08-10',
            usedDays: $usedDays,
            usageType: 'full',
            paidLeaveRequestId: $paidLeaveRequestId,
            viaReactor: $viaReactor,
            initiatedByUserId: $user->id,
        ));
    }

    /**
     * 業務ルール違反(DomainRuleException)が投げられることを確認する。
     */
    private function assertRejected(callable $operation): void
    {
        try {
            $operation();
        } catch (DomainRuleException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('DomainRuleException が投げられませんでした。');
    }

    private function aggregate(User $user): PaidLeaveAccountAggregate
    {
        return PaidLeaveAccountAggregate::retrieve($user->id);
    }

    private function eventCount(User $user): int
    {
        return EloquentStoredEvent::query()->where('aggregate_uuid', $user->id)->count();
    }

    // ---- Designate ----

    public function test_designate_via_reactor_twice_for_the_same_request_returns_the_same_usage_and_records_nothing_new(): void
    {
        $user = User::factory()->create();
        $this->grantFor($user);
        $requestId = (string) Str::uuid();

        $firstUsageId = $this->designate($user, $requestId, viaReactor: true);
        $countAfterFirst = $this->eventCount($user);
        $secondUsageId = $this->designate($user, $requestId, viaReactor: true);

        $this->assertSame($firstUsageId, $secondUsageId);
        $this->assertSame($countAfterFirst, $this->eventCount($user));
        $this->assertSame($firstUsageId, $this->aggregate($user)->usageIdForRequest($requestId));
    }

    public function test_designate_via_reactor_creates_a_new_usage_after_the_previous_one_was_cancelled(): void
    {
        $user = User::factory()->create();
        $this->grantFor($user);
        $requestId = (string) Str::uuid();

        $firstUsageId = $this->designate($user, $requestId, viaReactor: true);
        $this->bus()->dispatch(new CancelPaidLeaveUsage(
            userId: $user->id,
            usageId: $firstUsageId,
            cancelledByUserId: $user->id,
            reason: '差戻し',
            viaReactor: true,
        ));

        $resubmittedUsageId = $this->designate($user, $requestId, viaReactor: true);

        $this->assertNotSame($firstUsageId, $resubmittedUsageId);
        $this->assertSame('cancelled', $this->aggregate($user)->usageStatus($firstUsageId));
        $this->assertSame('designated', $this->aggregate($user)->usageStatus($resubmittedUsageId));
        $this->assertSame($resubmittedUsageId, $this->aggregate($user)->usageIdForRequest($requestId));
    }

    // ---- Confirm ----

    public function test_confirm_via_reactor_twice_by_usage_id_is_a_no_op_the_second_time(): void
    {
        $user = User::factory()->create();
        $this->grantFor($user);
        $usageId = $this->designate($user, (string) Str::uuid());

        $this->bus()->dispatch(new ConfirmPaidLeaveUsage(userId: $user->id, usageId: $usageId, confirmedByUserId: $user->id, viaReactor: true));
        $countAfterFirst = $this->eventCount($user);

        $this->bus()->dispatch(new ConfirmPaidLeaveUsage(userId: $user->id, usageId: $usageId, confirmedByUserId: $user->id, viaReactor: true));

        $this->assertSame($countAfterFirst, $this->eventCount($user));
        $this->assertSame('confirmed', $this->aggregate($user)->usageStatus($usageId));
    }

    public function test_confirm_without_reactor_twice_is_still_rejected(): void
    {
        $user = User::factory()->create();
        $this->grantFor($user);
        $usageId = $this->designate($user, (string) Str::uuid());

        $this->bus()->dispatch(new ConfirmPaidLeaveUsage(userId: $user->id, usageId: $usageId, confirmedByUserId: $user->id));
        $countAfterFirst = $this->eventCount($user);

        $this->assertRejected(
            fn () => $this->bus()->dispatch(new ConfirmPaidLeaveUsage(userId: $user->id, usageId: $usageId, confirmedByUserId: $user->id)),
        );

        $this->assertSame($countAfterFirst, $this->eventCount($user));
        $this->assertSame('confirmed', $this->aggregate($user)->usageStatus($usageId));
    }

    public function test_confirm_by_paid_leave_request_id_confirms_the_usage_designated_for_that_request(): void
    {
        $user = User::factory()->create();
        $this->grantFor($user);
        $requestId = (string) Str::uuid();
        $usageId = $this->designate($user, $requestId);

        $this->bus()->dispatch(new ConfirmPaidLeaveUsage(
            userId: $user->id,
            usageId: null,
            confirmedByUserId: $user->id,
            paidLeaveRequestId: $requestId,
            viaReactor: true,
        ));

        $this->assertSame('confirmed', $this->aggregate($user)->usageStatus($usageId));

        // 同じ申請IDで再度確定しても何もしない(viaReactor=true)。
        $countAfterFirst = $this->eventCount($user);
        $this->bus()->dispatch(new ConfirmPaidLeaveUsage(
            userId: $user->id,
            usageId: null,
            confirmedByUserId: $user->id,
            paidLeaveRequestId: $requestId,
            viaReactor: true,
        ));
        $this->assertSame($countAfterFirst, $this->eventCount($user));
    }

    public function test_confirm_by_unknown_paid_leave_request_id_is_rejected_even_via_reactor(): void
    {
        $user = User::factory()->create();
        $this->grantFor($user);

        $this->assertRejected(
            fn () => $this->bus()->dispatch(new ConfirmPaidLeaveUsage(
                userId: $user->id,
                usageId: null,
                confirmedByUserId: $user->id,
                paidLeaveRequestId: (string) Str::uuid(),
                viaReactor: true,
            )),
        );
    }

    public function test_confirm_is_rejected_without_recording_anything_when_balance_is_insufficient(): void
    {
        $user = User::factory()->create();
        $this->grantFor($user);
        $requestId = (string) Str::uuid();
        $usageId = $this->designate($user, $requestId, viaReactor: false, usedDays: 11.0);
        $countBefore = $this->eventCount($user);

        foreach ([true, false] as $viaReactor) {
            $this->assertRejected(
                fn () => $this->bus()->dispatch(new ConfirmPaidLeaveUsage(
                    userId: $user->id,
                    usageId: $usageId,
                    confirmedByUserId: $user->id,
                    viaReactor: $viaReactor,
                )),
            );
        }

        $this->assertSame($countBefore, $this->eventCount($user));
        $this->assertSame('designated', $this->aggregate($user)->usageStatus($usageId));
    }

    // ---- Cancel ----

    public function test_cancel_via_reactor_twice_is_a_no_op_the_second_time(): void
    {
        $user = User::factory()->create();
        $this->grantFor($user);
        $usageId = $this->designate($user, (string) Str::uuid());
        $this->bus()->dispatch(new ConfirmPaidLeaveUsage(userId: $user->id, usageId: $usageId, confirmedByUserId: $user->id));

        $this->bus()->dispatch(new CancelPaidLeaveUsage(userId: $user->id, usageId: $usageId, cancelledByUserId: $user->id, reason: '取消', viaReactor: true));
        $countAfterFirst = $this->eventCount($user);

        $this->bus()->dispatch(new CancelPaidLeaveUsage(userId: $user->id, usageId: $usageId, cancelledByUserId: $user->id, reason: '取消', viaReactor: true));

        $this->assertSame($countAfterFirst, $this->eventCount($user));
        $this->assertSame('cancelled', $this->aggregate($user)->usageStatus($usageId));
    }

    public function test_cancel_without_reactor_twice_is_still_rejected(): void
    {
        $user = User::factory()->create();
        $this->grantFor($user);
        $usageId = $this->designate($user, (string) Str::uuid());

        $this->bus()->dispatch(new CancelPaidLeaveUsage(userId: $user->id, usageId: $usageId, cancelledByUserId: $user->id, reason: '取消'));
        $countAfterFirst = $this->eventCount($user);

        $this->assertRejected(
            fn () => $this->bus()->dispatch(new CancelPaidLeaveUsage(userId: $user->id, usageId: $usageId, cancelledByUserId: $user->id, reason: '取消')),
        );

        $this->assertSame($countAfterFirst, $this->eventCount($user));
    }

    public function test_cancel_via_reactor_is_a_no_op_when_no_usage_exists_for_the_request(): void
    {
        $user = User::factory()->create();
        $this->grantFor($user);
        $countBefore = $this->eventCount($user);

        $this->bus()->dispatch(new CancelPaidLeaveUsage(
            userId: $user->id,
            usageId: null,
            cancelledByUserId: $user->id,
            reason: '取消',
            paidLeaveRequestId: (string) Str::uuid(),
            viaReactor: true,
        ));

        $this->assertSame($countBefore, $this->eventCount($user));
    }

    public function test_cancel_without_reactor_is_rejected_when_no_usage_exists_for_the_request(): void
    {
        $user = User::factory()->create();
        $this->grantFor($user);

        $this->assertRejected(
            fn () => $this->bus()->dispatch(new CancelPaidLeaveUsage(
                userId: $user->id,
                usageId: null,
                cancelledByUserId: $user->id,
                reason: '取消',
                paidLeaveRequestId: (string) Str::uuid(),
            )),
        );
    }

    public function test_cancel_by_paid_leave_request_id_cancels_the_usage_and_releases_its_allocation(): void
    {
        $user = User::factory()->create();
        $this->grantFor($user);
        $requestId = (string) Str::uuid();
        $usageId = $this->designate($user, $requestId, usedDays: 2.0);
        $this->bus()->dispatch(new ConfirmPaidLeaveUsage(userId: $user->id, usageId: $usageId, confirmedByUserId: $user->id));
        $this->assertSame('confirmed', $this->aggregate($user)->usageStatus($usageId));

        $this->bus()->dispatch(new CancelPaidLeaveUsage(
            userId: $user->id,
            usageId: null,
            cancelledByUserId: $user->id,
            reason: '取消',
            paidLeaveRequestId: $requestId,
            viaReactor: true,
        ));

        $this->assertSame('cancelled', $this->aggregate($user)->usageStatus($usageId));
        $this->assertFalse($this->aggregate($user)->hasActiveUsageForRequest($requestId));
    }

    public function test_command_requires_either_usage_id_or_paid_leave_request_id(): void
    {
        $user = User::factory()->create();

        $this->expectException(\InvalidArgumentException::class);

        new ConfirmPaidLeaveUsage(userId: $user->id, usageId: null, confirmedByUserId: $user->id);
    }
}
