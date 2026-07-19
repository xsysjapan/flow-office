<?php

namespace Tests\Feature\Notification;

use App\Jobs\SendNotificationJob;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * UC-N001: 自分宛て通知の一覧・既読管理。
 */
class NotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_only_the_authenticated_users_notifications(): void
    {
        $recipient = User::factory()->create();
        $other = User::factory()->create();

        SendNotificationJob::enqueue($recipient, '承認依頼', '概要1', null);
        SendNotificationJob::enqueue($other, '承認依頼', '概要2', null);

        $response = $this->actingAs($recipient)->getJson('/api/notifications/mine');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $response->assertJsonPath('data.0.title', '承認依頼');
        $response->assertJsonPath('data.0.summary', '概要1');
    }

    public function test_it_filters_by_unread_and_read_status(): void
    {
        $recipient = User::factory()->create();
        SendNotificationJob::enqueue($recipient, '件名', '概要', null);
        $notification = Notification::query()->firstOrFail();

        $this->actingAs($recipient)->postJson("/api/notifications/{$notification->id}/confirm")->assertOk();

        $unread = $this->actingAs($recipient)->getJson('/api/notifications/mine?status=unread');
        $this->assertCount(0, $unread->json('data'));

        $read = $this->actingAs($recipient)->getJson('/api/notifications/mine?status=read');
        $this->assertCount(1, $read->json('data'));
    }

    public function test_confirming_marks_the_notification_as_confirmed(): void
    {
        $recipient = User::factory()->create();
        SendNotificationJob::enqueue($recipient, '件名', '概要', null);
        $notification = Notification::query()->firstOrFail();
        $this->assertNull($notification->confirmed_at);

        $response = $this->actingAs($recipient)->postJson("/api/notifications/{$notification->id}/confirm");

        $response->assertOk();
        $this->assertNotNull($response->json('confirmed_at'));
    }

    public function test_confirming_is_idempotent(): void
    {
        $recipient = User::factory()->create();
        SendNotificationJob::enqueue($recipient, '件名', '概要', null);
        $notification = Notification::query()->firstOrFail();

        $this->actingAs($recipient)->postJson("/api/notifications/{$notification->id}/confirm")->assertOk();
        $this->actingAs($recipient)->postJson("/api/notifications/{$notification->id}/confirm")->assertOk();
    }

    public function test_it_forbids_confirming_someone_elses_notification(): void
    {
        $recipient = User::factory()->create();
        $stranger = User::factory()->create();
        SendNotificationJob::enqueue($recipient, '件名', '概要', null);
        $notification = Notification::query()->firstOrFail();

        $this->actingAs($stranger)->postJson("/api/notifications/{$notification->id}/confirm")
            ->assertStatus(422);

        $this->assertNull($notification->refresh()->confirmed_at);
    }
}
