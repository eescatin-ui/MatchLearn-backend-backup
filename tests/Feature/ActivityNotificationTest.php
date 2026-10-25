<?php

namespace Tests\Feature;

use App\Models\ActivityNotification;
use App\Models\StudySession;
use App\Models\User;
use App\Services\ActivityNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ActivityNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_notifications_are_deduplicated_per_user_and_read_state_is_private(): void
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $notifications = app(ActivityNotificationService::class);

        $notifications->send($firstUser->id, 'shared-event', 'session_invitation', 'Invite', 'Study together.');
        $notifications->send($secondUser->id, 'shared-event', 'session_invitation', 'Invite', 'Study together.');
        $notifications->send($firstUser->id, 'shared-event', 'session_invitation', 'Invite', 'Study together.');

        $this->assertDatabaseCount('activity_notifications', 2);

        $firstNotificationId = ActivityNotification::query()
            ->where('user_id', $firstUser->id)
            ->value('id');
        $this->actingAs($firstUser, 'sanctum')
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1)
            ->assertJsonCount(1, 'data.notifications');

        $this->actingAs($firstUser, 'sanctum')
            ->patchJson("/api/v1/notifications/{$firstNotificationId}/read")
            ->assertOk();

        $this->actingAs($secondUser, 'sanctum')
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1);

        $this->actingAs($secondUser, 'sanctum')
            ->patchJson("/api/v1/notifications/{$firstNotificationId}/read")
            ->assertNotFound();
    }

    public function test_notification_feed_creates_idempotent_today_and_tomorrow_session_reminders(): void
    {
        $this->travelTo(Carbon::parse('2026-10-08 12:00:00', 'Asia/Manila'));
        $host = User::factory()->create();
        $participant = User::factory()->create();
        $todayStart = Carbon::now('Asia/Manila')->startOfDay();

        foreach ([0, 1] as $daysAhead) {
            $startsAt = $todayStart->copy()->addDays($daysAhead)->addHours(15);
            StudySession::create([
                'host_user_id' => $host->id,
                'participant_user_id' => $participant->id,
                'topic' => "Session in {$daysAhead} days",
                'starts_at' => $startsAt->copy()->utc(),
                'ends_at' => $startsAt->copy()->addHour()->utc(),
                'location' => 'Library',
                'status' => 'confirmed',
            ]);
        }

        $pendingSession = StudySession::create([
            'host_user_id' => $host->id,
            'participant_user_id' => $participant->id,
            'topic' => 'Pending session',
            'starts_at' => $todayStart->copy()->addDay()->addHours(16)->utc(),
            'ends_at' => $todayStart->copy()->addDay()->addHours(17)->utc(),
            'location' => 'Library',
            'status' => 'pending',
        ]);

        $response = $this->actingAs($participant, 'sanctum')
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonFragment(['type' => 'session_today'])
            ->assertJsonFragment(['type' => 'session_tomorrow']);
        $this->assertDatabaseCount('activity_notifications', 2);
        $this->assertNotContains(
            $pendingSession->id,
            collect($response->json('data.notifications'))->pluck('data.session_id')->all()
        );

        $this->actingAs($participant, 'sanctum')
            ->getJson('/api/v1/notifications')
            ->assertOk();
        $this->assertDatabaseCount('activity_notifications', 2);
    }

    public function test_user_can_delete_selected_notifications_without_deleting_another_users_items(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $notifications = app(ActivityNotificationService::class);
        $notifications->send($user->id, 'own-unread', 'buddy_request', 'Request', 'A request.');
        $notifications->send($user->id, 'own-read', 'session_invitation', 'Session', 'A session.');
        $notifications->send($otherUser->id, 'other-unread', 'buddy_request', 'Request', 'Another request.');

        $ownNotifications = ActivityNotification::query()->where('user_id', $user->id)->get();
        $otherNotificationId = ActivityNotification::query()->where('user_id', $otherUser->id)->value('id');
        $readNotification = $ownNotifications->firstWhere('event_key', 'own-read');
        $readNotification->update(['read_at' => now()]);

        $this->actingAs($user, 'sanctum')
            ->deleteJson('/api/v1/notifications', [
                'ids' => $ownNotifications->pluck('id')->all(),
            ])
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);

        $this->assertDatabaseMissing('activity_notifications', ['user_id' => $user->id]);
        $this->assertDatabaseHas('activity_notifications', ['id' => $otherNotificationId, 'user_id' => $otherUser->id]);
    }
}
