<?php

namespace Tests\Unit\EventSourcing\Rebuild;

use App\Domain\EventSourcing\Rebuild\EventStoreRebuilder;
use App\Domain\EventSourcing\Rebuild\PaidLeaveScheduleCurrent;
use App\Domain\EventSourcing\Rebuild\PaidLeaveScheduleRebuilder;
use App\Domain\EventSourcing\Rebuild\RebuiltEvent;
use App\Domain\EventSourcing\Rebuild\StoredEventRow;
use PHPUnit\Framework\TestCase;

/**
 * 作り直し後のイベントの発生日時(論点19 決定10-8。決定8-5・9-4を置き換える):
 * meta_data の created-at を常に使い、created-at が無い・解釈できないときだけ列 created_at の値を使う。
 */
class OccurredAtTest extends TestCase
{
    public function test_pass_through_uses_meta_created_at_when_column_is_one_second_later(): void
    {
        $event = RebuiltEvent::passThrough($this->row('2026-09-01 10:00:01', '2026-09-01 10:00:00'));

        $this->assertSame('2026-09-01 10:00:00', $event->createdAt);
    }

    public function test_pass_through_uses_meta_created_at_when_column_is_several_days_later(): void
    {
        // 保守の書き換えで列が後の時刻になった行(決定8-5の改訂の理由)。
        $event = RebuiltEvent::passThrough($this->row('2026-09-05 10:00:00', '2026-09-01 10:00:00'));

        $this->assertSame('2026-09-01 10:00:00', $event->createdAt);
    }

    public function test_pass_through_uses_meta_created_at_when_column_is_earlier(): void
    {
        // 負のずれ(列が meta より前)でも meta を使う。
        $event = RebuiltEvent::passThrough($this->row('2026-08-30 10:00:00', '2026-09-01 10:00:00'));

        $this->assertSame('2026-09-01 10:00:00', $event->createdAt);
    }

    public function test_pass_through_uses_meta_created_at_when_shift_is_nine_hours(): void
    {
        $this->assertSame('2026-09-01 10:00:00', RebuiltEvent::passThrough($this->row('2026-09-01 19:00:00', '2026-09-01 10:00:00'))->createdAt);
        $this->assertSame('2026-09-01 10:00:00', RebuiltEvent::passThrough($this->row('2026-09-01 01:00:00', '2026-09-01 10:00:00'))->createdAt);
    }

    public function test_pass_through_uses_meta_created_at_when_shift_is_nine_hours_and_three_seconds(): void
    {
        $this->assertSame('2026-09-01 10:00:00', RebuiltEvent::passThrough($this->row('2026-09-01 19:00:03', '2026-09-01 10:00:00'))->createdAt);
    }

    public function test_pass_through_uses_column_when_meta_has_no_created_at(): void
    {
        $event = RebuiltEvent::passThrough($this->row('2026-09-01 10:00:01', null));

        $this->assertSame('2026-09-01 10:00:01', $event->createdAt);
    }

    public function test_pass_through_uses_column_when_meta_created_at_is_not_a_date(): void
    {
        $event = RebuiltEvent::passThrough($this->row('2026-09-01 10:00:01', 'not-a-date'));

        $this->assertSame('2026-09-01 10:00:01', $event->createdAt);
    }

    public function test_derived_event_follows_the_anchor_rule(): void
    {
        $near = RebuiltEvent::derived($this->row('2026-09-01 10:00:01', '2026-09-01 10:00:00'), 1, 'x.created', 'agg', []);
        $far = RebuiltEvent::derived($this->row('2026-09-05 10:00:00', '2026-09-01 10:00:00'), 1, 'x.created', 'agg', []);

        $this->assertSame('2026-09-01 10:00:00', $near->createdAt);
        $this->assertSame('2026-09-01 10:00:00', $far->createdAt);
    }

    public function test_explicitly_specified_derived_event_keeps_the_specified_value(): void
    {
        $anchor = $this->row('2026-09-01 10:00:01', '2026-09-01 10:00:00', 'paid_leave_schedule.entry_created', ['entryId' => 'entry-1']);
        $current = new PaidLeaveScheduleCurrent(...[
            'id' => 'entry-1', 'userId' => 'u1', 'scheduledOn' => '2026-10-01 00:00:00', 'category' => 'standard', 'candidateGrantDays' => 10.0,
            'status' => 'Scheduled', 'isManuallyOverridden' => false, 'manualOverrideReason' => null, 'manualOverrideByUserId' => null,
            'grantId' => null, 'cancelledReason' => null, 'createdAt' => '2026-09-01 09:00:00', 'manualOverrideAt' => null, 'updatedAt' => null,
            'assessments' => [],
        ]);

        $result = (new PaidLeaveScheduleRebuilder)->rebuild([$anchor], ['entry-1' => $current]);

        $this->assertSame('2026-09-01 09:00:00', $result->events[0]->createdAt);
    }

    public function test_rebuilt_rows_have_the_same_created_at_in_meta_and_column(): void
    {
        $rows = [
            $this->row('2026-09-01 10:00:01', '2026-09-01 10:00:00', 'shift_pattern.created', [], 1, 'a1'),
            $this->row('2026-09-05 10:00:03', '2026-09-01 10:00:00', 'shift_pattern.created', [], 2, 'a2'),
            $this->row('2026-09-01 10:00:07', null, 'shift_pattern.created', [], 3, 'a3'),
        ];

        $result = (new EventStoreRebuilder)->rebuild($rows);

        $this->assertTrue($result->ok, json_encode($result->unsupported));
        $this->assertSame(['2026-09-01 10:00:00', '2026-09-01 10:00:00', '2026-09-01 10:00:07'], array_column($result->rows, 'created_at'));
        foreach ($result->rows as $row) {
            $this->assertSame($row['created_at'], $row['meta_data']['created-at']);
        }
    }

    /** @param  array<string, mixed>  $payload */
    private function row(string $column, ?string $metaCreatedAt, string $class = 'x.created', array $payload = [], int $id = 1, string $aggregate = 'agg'): StoredEventRow
    {
        $meta = ['aggregate-root-uuid' => $aggregate];
        if ($metaCreatedAt !== null) {
            $meta['created-at'] = $metaCreatedAt;
        }

        return new StoredEventRow($id, $aggregate, 1, $class, $payload, $meta, $column);
    }
}
