<?php

namespace Tests\Unit\EventSourcing\Rebuild;

use App\Domain\EventSourcing\Rebuild\PaidLeaveGrantRebuilder;
use PHPUnit\Framework\TestCase;

/**
 * 旧 paid_leave.granted の source 判定(定期付与の固定の grantReason 形式で scheduled_batch、それ以外は manual)。
 */
class PaidLeaveGrantSourceTest extends TestCase
{
    public function test_scheduled_grant_reason_is_scheduled_batch(): void
    {
        $this->assertSame('scheduled_batch', PaidLeaveGrantRebuilder::sourceOf('自動付与（標準ルール、勤続6か月）'));
    }

    public function test_manual_or_missing_reason_is_manual(): void
    {
        $this->assertSame('manual', PaidLeaveGrantRebuilder::sourceOf('入社時の手動付与'));
        $this->assertSame('manual', PaidLeaveGrantRebuilder::sourceOf(null));
    }
}
