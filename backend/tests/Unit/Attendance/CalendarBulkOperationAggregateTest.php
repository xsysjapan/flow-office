<?php

namespace Tests\Unit\Attendance;

use App\Domain\Attendance\Aggregates\CalendarBulkOperationAggregate;
use App\Domain\Attendance\Events\CalendarBulkOperationApplied;
use App\Domain\Attendance\Events\CalendarBulkOperationReverted;
use App\Domain\EventSourcing\Exceptions\DomainRuleException;
use Tests\TestCase;

/**
 * 一括操作の取消の業務ルール: 取消済みの操作は重ねて取り消せない(docs/changesets/20261123-rebuild-drift-fixes/spec.md 論点5)。
 */
class CalendarBulkOperationAggregateTest extends TestCase
{
    private function applied(): CalendarBulkOperationApplied
    {
        return new CalendarBulkOperationApplied('bulk_edit', [], 'skip_existing', '理由', [], 'user-1');
    }

    private function reverted(): CalendarBulkOperationReverted
    {
        return new CalendarBulkOperationReverted([], [], 'user-1');
    }

    public function test_an_applied_operation_can_be_reverted(): void
    {
        CalendarBulkOperationAggregate::fake()
            ->given([$this->applied()])
            ->when(fn (CalendarBulkOperationAggregate $aggregate) => $aggregate->revert(['t1'], [], 'user-2'))
            ->assertRecorded([new CalendarBulkOperationReverted(['t1'], [], 'user-2')]);
    }

    public function test_a_reverted_operation_cannot_be_reverted_again(): void
    {
        $this->expectException(DomainRuleException::class);

        CalendarBulkOperationAggregate::fake()
            ->given([$this->applied(), $this->reverted()])
            ->when(fn (CalendarBulkOperationAggregate $aggregate) => $aggregate->revert([], [], 'user-2'));
    }

    public function test_a_second_revert_in_the_same_unit_of_work_is_rejected(): void
    {
        $aggregate = CalendarBulkOperationAggregate::fake()->given([$this->applied()]);
        $this->expectException(DomainRuleException::class);

        $aggregate->when(function (CalendarBulkOperationAggregate $aggregate) {
            $aggregate->revert([], [], 'user-2');
            $aggregate->revert([], [], 'user-2');
        });
    }

    public function test_replaying_a_history_with_a_double_revert_does_not_throw(): void
    {
        $fake = CalendarBulkOperationAggregate::fake()
            ->given([$this->applied(), $this->reverted(), $this->reverted()]);

        $this->assertInstanceOf(CalendarBulkOperationAggregate::class, $fake->aggregateRoot());
    }
}
