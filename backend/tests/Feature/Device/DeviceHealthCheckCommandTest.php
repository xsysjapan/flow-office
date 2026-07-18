<?php

namespace Tests\Feature\Device;

use App\Models\Device;
use App\Models\DeviceStatus;
use App\Models\StoredEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DeviceHealthCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_devices_with_no_recent_heartbeat(): void
    {
        Device::factory()->create([
            'name' => '疎通中の端末',
            'status' => DeviceStatus::ACTIVE,
            'last_seen_at' => Carbon::now(),
        ]);
        Device::factory()->create([
            'name' => '疎通が途絶えた端末',
            'status' => DeviceStatus::ACTIVE,
            'last_seen_at' => Carbon::now()->subHours(72),
        ]);

        $this->artisan('devices:health-check', ['--stale-after-hours' => 48])
            ->expectsOutputToContain('疎通が途絶えた端末')
            ->assertExitCode(0);
    }

    public function test_it_reports_nothing_when_all_devices_are_healthy(): void
    {
        Device::factory()->create(['status' => DeviceStatus::ACTIVE, 'last_seen_at' => Carbon::now()]);

        $this->artisan('devices:health-check')
            ->expectsOutputToContain('疎通が途絶えている端末はありません。')
            ->assertExitCode(0);
    }

    public function test_it_queues_a_teams_notification_when_devices_are_stale(): void
    {
        Device::factory()->create([
            'name' => '疎通が途絶えた端末',
            'status' => DeviceStatus::ACTIVE,
            'last_seen_at' => Carbon::now()->subHours(72),
        ]);

        $this->artisan('devices:health-check', ['--stale-after-hours' => 48])->assertExitCode(0);

        $notification = StoredEvent::query()
            ->where('aggregate_type', 'notification')
            ->where('event_type', 'notification.queued')
            ->latest('id')
            ->first();

        $this->assertNotNull($notification);
        $this->assertSame('端末の疎通が途絶えています', $notification->payload['title']);
        $this->assertStringContainsString('疎通が途絶えた端末', $notification->payload['summary']);
    }

    public function test_it_does_not_queue_a_teams_notification_when_all_devices_are_healthy(): void
    {
        Device::factory()->create(['status' => DeviceStatus::ACTIVE, 'last_seen_at' => Carbon::now()]);

        $this->artisan('devices:health-check')->assertExitCode(0);

        $this->assertSame(0, StoredEvent::query()->where('aggregate_type', 'notification')->count());
    }
}
