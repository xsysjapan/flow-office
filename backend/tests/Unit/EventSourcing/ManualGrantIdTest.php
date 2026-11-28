<?php

namespace Tests\Unit\EventSourcing;

use App\Domain\EventSourcing\ManualGrantId;
use PHPUnit\Framework\TestCase;

class ManualGrantIdTest extends TestCase
{
    public function test_the_same_request_and_user_give_the_same_id(): void
    {
        $this->assertSame(
            ManualGrantId::for('paid_leave', 'req-1', 'user-1'),
            ManualGrantId::for('paid_leave', 'req-1', 'user-1'),
        );
    }

    public function test_a_different_request_user_or_kind_gives_a_different_id(): void
    {
        $base = ManualGrantId::for('paid_leave', 'req-1', 'user-1');

        $this->assertNotSame($base, ManualGrantId::for('paid_leave', 'req-2', 'user-1'));
        $this->assertNotSame($base, ManualGrantId::for('paid_leave', 'req-1', 'user-2'));
        $this->assertNotSame($base, ManualGrantId::for('special_leave', 'req-1', 'user-1'));
        $this->assertNotSame($base, ManualGrantId::for('compensatory_leave', 'req-1', 'user-1'));
    }

    public function test_the_id_is_a_uuid(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/', ManualGrantId::for('paid_leave', 'req-1', 'user-1'));
    }
}
