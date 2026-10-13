<?php

namespace Tests\Unit\EventSourcing\Rebuild;

use App\Domain\EventSourcing\Rebuild\ReadModelTableLister;
use Tests\TestCase;

/**
 * Projectorがリレーション経由(->roles()->create 等)で書くテーブルも一覧に含まれること。
 */
class ReadModelTableListerTest extends TestCase
{
    public function test_リレーション経由で書くテーブルも拾う(): void
    {
        $tables = (new ReadModelTableLister)->tables(app_path('Domain'));

        $this->assertArrayHasKey('device_roles', $tables);
        $this->assertArrayHasKey('device_scopes', $tables);
        $this->assertContains('Device/Projectors/DeviceProjector.php', $tables['device_roles']);
        $this->assertContains('Device/Projectors/DeviceProjector.php', $tables['device_scopes']);

        $this->assertArrayHasKey('attendance_breaks', $tables);
        $this->assertArrayHasKey('attendance_leave_segments', $tables);
        $this->assertContains('Attendance/Projectors/AttendanceDayProjector.php', $tables['attendance_breaks']);
        $this->assertContains('Attendance/Projectors/AttendanceDayProjector.php', $tables['attendance_leave_segments']);
    }
}
