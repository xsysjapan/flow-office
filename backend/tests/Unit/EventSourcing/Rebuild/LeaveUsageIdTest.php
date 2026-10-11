<?php

namespace Tests\Unit\EventSourcing\Rebuild;

use App\Domain\EventSourcing\Rebuild\LeaveUsageId;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

class LeaveUsageIdTest extends TestCase
{
    public function test_same_inputs_give_same_uuid_and_valid_format(): void
    {
        $a = LeaveUsageId::for('paid_leave', 'req-1', 0);
        $b = LeaveUsageId::for('paid_leave', 'req-1', 0);

        $this->assertSame($a, $b);
        $this->assertTrue(Uuid::isValid($a));
    }

    public function test_kind_request_and_resubmit_count_each_change_the_id(): void
    {
        $base = LeaveUsageId::for('paid_leave', 'req-1', 0);

        $this->assertNotSame($base, LeaveUsageId::for('paid_leave', 'req-1', 1));
        $this->assertNotSame($base, LeaveUsageId::for('paid_leave', 'req-2', 0));
        $this->assertNotSame($base, LeaveUsageId::for('special_leave', 'req-1', 0));
    }

    public function test_invalid_arguments_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        LeaveUsageId::for('paid_leave', 'req-1', -1);
    }
}
