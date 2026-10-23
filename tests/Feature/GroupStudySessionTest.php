<?php

namespace Tests\Feature;

use App\Models\StudySession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GroupStudySessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_can_invite_a_joined_group_and_group_members_can_respond(): void
    {
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $outsider = User::factory()->create();
        $groupId = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/groups', [
                'name' => 'Exam prep',
                'course' => 'IT 213',
                'avatar_icon' => 'flask-outline',
                'avatar_color' => '#15803d',
            ])
            ->assertCreated()
            ->json('data.group.id');

        $requestId = $this->actingAs($member, 'sanctum')
            ->postJson("/api/v1/groups/{$groupId}/join")
            ->assertCreated()
            ->json('data.request_id');
        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/groups/{$groupId}/join-requests/{$requestId}", ['action' => 'accept'])
            ->assertOk();

        $sessionId = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/sessions', [
                'study_group_id' => $groupId,
                'topic' => 'Final review',
                'starts_at' => now()->addDay()->toIso8601String(),
                'ends_at' => now()->addDay()->addMinutes(30)->toIso8601String(),
                'location' => 'Library',
            ])
            ->assertCreated()
            ->assertJsonPath('data.session.target_type', 'group')
            ->assertJsonPath('data.session.other_user_name', 'Exam prep')
            ->assertJsonPath('data.session.group_avatar_icon', 'flask-outline')
            ->assertJsonPath('data.session.group_avatar_color', '#15803d')
            ->json('data.session.id');
        $this->assertDatabaseHas('activity_notifications', [
            'user_id' => $member->id,
            'type' => 'session_invitation',
        ]);

        $this->actingAs($member, 'sanctum')
            ->getJson('/api/v1/sessions')
            ->assertOk()
            ->assertJsonPath('data.sessions.0.id', $sessionId);

        $this->actingAs($outsider, 'sanctum')
            ->getJson('/api/v1/sessions')
            ->assertOk()
            ->assertJsonCount(0, 'data.sessions');

        $this->actingAs($member, 'sanctum')
            ->patchJson("/api/v1/sessions/{$sessionId}", ['status' => 'confirmed'])
            ->assertOk()
            ->assertJsonPath('data.session.status', 'confirmed');
        $this->assertDatabaseHas('activity_notifications', [
            'user_id' => $admin->id,
            'type' => 'session_accepted',
        ]);

        $this->assertDatabaseHas('study_sessions', [
            'id' => $sessionId,
            'study_group_id' => $groupId,
            'participant_user_id' => null,
            'status' => 'confirmed',
        ]);

        $this->actingAs($member, 'sanctum')
            ->patchJson("/api/v1/sessions/{$sessionId}", ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('data.session.status', 'cancelled');
        $this->actingAs($member, 'sanctum')
            ->deleteJson("/api/v1/sessions/{$sessionId}")
            ->assertOk()
            ->assertJsonPath('message', 'Study session removed from your list.');
        $this->actingAs($member, 'sanctum')
            ->getJson('/api/v1/sessions')
            ->assertOk()
            ->assertJsonCount(0, 'data.sessions');
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/sessions')
            ->assertOk()
            ->assertJsonPath('data.sessions.0.id', $sessionId);
        $this->assertDatabaseHas('study_sessions', [
            'id' => $sessionId,
            'status' => 'cancelled',
        ]);
    }

    public function test_non_members_cannot_create_or_respond_to_group_sessions(): void
    {
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $outsider = User::factory()->create();
        $groupId = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/groups', ['name' => 'Private group', 'course' => 'IT 214'])
            ->assertCreated()
            ->json('data.group.id');

        $requestId = $this->actingAs($member, 'sanctum')
            ->postJson("/api/v1/groups/{$groupId}/join")
            ->assertCreated()
            ->json('data.request_id');
        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/groups/{$groupId}/join-requests/{$requestId}", ['action' => 'accept'])
            ->assertOk();

        $sessionId = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/sessions', [
                'study_group_id' => $groupId,
                'topic' => 'Study',
                'starts_at' => now()->addDay()->toIso8601String(),
                'ends_at' => now()->addDay()->addMinutes(30)->toIso8601String(),
                'location' => 'Library',
            ])
            ->assertCreated()
            ->json('data.session.id');

        $this->actingAs($outsider, 'sanctum')
            ->postJson('/api/v1/sessions', [
                'study_group_id' => $groupId,
                'topic' => 'Study',
                'starts_at' => now()->addDay()->toIso8601String(),
                'ends_at' => now()->addDay()->addMinutes(30)->toIso8601String(),
                'location' => 'Library',
            ])
            ->assertForbidden();

        $this->actingAs($outsider, 'sanctum')
            ->patchJson("/api/v1/sessions/{$sessionId}", ['status' => 'confirmed'])
            ->assertNotFound();

        $this->assertSame(1, StudySession::query()->count());
    }

    public function test_group_can_only_be_rated_after_a_completed_group_session(): void
    {
        $admin = User::factory()->create();
        $groupId = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/groups', ['name' => 'Exam prep', 'course' => 'IT 213'])
            ->assertCreated()
            ->json('data.group.id');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/groups/{$groupId}/reviews", ['rating' => 5])
            ->assertForbidden()
            ->assertJsonPath('message', 'Complete at least one study session with this group before rating it.');

        StudySession::create([
            'host_user_id' => $admin->id,
            'participant_user_id' => null,
            'study_group_id' => $groupId,
            'topic' => 'Previous group session',
            'starts_at' => now()->subHours(2),
            'ends_at' => now()->subHour(),
            'location' => 'Library',
            'status' => 'completed',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/groups/{$groupId}/reviews", ['rating' => 5])
            ->assertOk();
    }

    public function test_session_must_target_exactly_one_buddy_or_group(): void
    {
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $groupId = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/groups', ['name' => 'Select one', 'course' => 'IT 215'])
            ->assertCreated()
            ->json('data.group.id');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/sessions', [
                'participant_user_id' => $member->id,
                'study_group_id' => $groupId,
                'topic' => 'Study',
                'starts_at' => now()->addDay()->toIso8601String(),
                'ends_at' => now()->addDay()->addMinutes(30)->toIso8601String(),
                'location' => 'Library',
            ])
            ->assertUnprocessable();
    }

    public function test_session_end_must_be_after_the_start(): void
    {
        $admin = User::factory()->create();
        $groupId = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/groups', ['name' => 'Timing group', 'course' => 'IT 216'])
            ->assertCreated()
            ->json('data.group.id');
        $startsAt = now()->addDay();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/sessions', [
                'study_group_id' => $groupId,
                'topic' => 'Study',
                'starts_at' => $startsAt->toIso8601String(),
                'ends_at' => $startsAt->toIso8601String(),
                'location' => 'Library',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ends_at');
    }

    public function test_only_creator_can_edit_an_upcoming_session(): void
    {
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $groupId = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/groups', ['name' => 'Editable group', 'course' => 'IT 217'])
            ->assertCreated()
            ->json('data.group.id');
        $requestId = $this->actingAs($member, 'sanctum')
            ->postJson("/api/v1/groups/{$groupId}/join")
            ->assertCreated()
            ->json('data.request_id');
        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/groups/{$groupId}/join-requests/{$requestId}", ['action' => 'accept'])
            ->assertOk();

        $sessionId = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/sessions', [
                'study_group_id' => $groupId,
                'topic' => 'Original topic',
                'starts_at' => now()->addDay()->toIso8601String(),
                'ends_at' => now()->addDay()->addHour()->toIso8601String(),
                'location' => 'Library',
            ])
            ->assertCreated()
            ->json('data.session.id');

        $newStart = now()->addDays(2);
        $this->actingAs($member, 'sanctum')
            ->patchJson("/api/v1/sessions/{$sessionId}", [
                'topic' => 'Updated topic',
                'starts_at' => $newStart->toIso8601String(),
                'ends_at' => $newStart->addHour()->toIso8601String(),
                'location' => 'Study hall',
            ])
            ->assertForbidden();

        $newStart = now()->addDays(2);
        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/sessions/{$sessionId}", [
                'topic' => 'Updated topic',
                'starts_at' => $newStart->toIso8601String(),
                'ends_at' => $newStart->addHour()->toIso8601String(),
                'location' => 'Study hall',
            ])
            ->assertOk()
            ->assertJsonPath('data.session.topic', 'Updated topic')
            ->assertJsonPath('data.session.location', 'Study hall');

        $this->assertDatabaseHas('study_sessions', [
            'id' => $sessionId,
            'topic' => 'Updated topic',
            'location' => 'Study hall',
        ]);
    }

    public function test_creator_can_reschedule_or_delete_cancelled_and_completed_sessions(): void
    {
        $host = User::factory()->create();
        $participant = User::factory()->create();
        $cancelledSession = StudySession::create([
            'host_user_id' => $host->id,
            'participant_user_id' => $participant->id,
            'study_group_id' => null,
            'topic' => 'Cancelled review',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->subDay()->addHour(),
            'location' => 'Library',
            'status' => 'cancelled',
        ]);

        $newStart = now()->addDays(2);
        $this->actingAs($participant, 'sanctum')
            ->deleteJson("/api/v1/sessions/{$cancelledSession->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Study session removed from your list.');
        $this->actingAs($participant, 'sanctum')
            ->getJson('/api/v1/sessions')
            ->assertOk()
            ->assertJsonCount(0, 'data.sessions');
        $this->actingAs($host, 'sanctum')
            ->getJson('/api/v1/sessions')
            ->assertOk()
            ->assertJsonPath('data.sessions.0.id', $cancelledSession->id);

        $this->actingAs($host, 'sanctum')
            ->patchJson("/api/v1/sessions/{$cancelledSession->id}", [
                'status' => 'pending',
                'topic' => 'Rescheduled review',
                'starts_at' => $newStart->toIso8601String(),
                'ends_at' => $newStart->copy()->addHour()->toIso8601String(),
                'location' => 'Study hall',
            ])
            ->assertOk()
            ->assertJsonPath('data.session.status', 'pending')
            ->assertJsonPath('data.session.topic', 'Rescheduled review');
        $this->assertDatabaseHas('activity_notifications', [
            'user_id' => $participant->id,
            'type' => 'session_invitation',
        ]);
        $this->actingAs($participant, 'sanctum')
            ->patchJson("/api/v1/sessions/{$cancelledSession->id}", ['status' => 'confirmed'])
            ->assertOk()
            ->assertJsonPath('data.session.status', 'confirmed');

        $this->actingAs($host, 'sanctum')
            ->patchJson("/api/v1/sessions/{$cancelledSession->id}", ['status' => 'cancelled'])
            ->assertOk();
        $this->actingAs($host, 'sanctum')
            ->deleteJson("/api/v1/sessions/{$cancelledSession->id}")
            ->assertOk();
        $this->assertDatabaseMissing('study_sessions', ['id' => $cancelledSession->id]);

        $completedSession = StudySession::create([
            'host_user_id' => $host->id,
            'participant_user_id' => $participant->id,
            'study_group_id' => null,
            'topic' => 'Completed review',
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subDays(2)->addHour(),
            'location' => 'Library',
            'status' => 'completed',
        ]);

        $this->actingAs($host, 'sanctum')
            ->deleteJson("/api/v1/sessions/{$completedSession->id}")
            ->assertOk();
        $this->assertDatabaseMissing('study_sessions', ['id' => $completedSession->id]);
    }

    public function test_rescheduling_a_group_session_sends_a_new_invitation_to_members(): void
    {
        $host = User::factory()->create();
        $member = User::factory()->create();
        $groupId = $this->actingAs($host, 'sanctum')
            ->postJson('/api/v1/groups', ['name' => 'Reschedule group', 'course' => 'IT 218'])
            ->assertCreated()
            ->json('data.group.id');
        $requestId = $this->actingAs($member, 'sanctum')
            ->postJson("/api/v1/groups/{$groupId}/join")
            ->assertCreated()
            ->json('data.request_id');
        $this->actingAs($host, 'sanctum')
            ->patchJson("/api/v1/groups/{$groupId}/join-requests/{$requestId}", ['action' => 'accept'])
            ->assertOk();

        $session = StudySession::create([
            'host_user_id' => $host->id,
            'participant_user_id' => null,
            'study_group_id' => $groupId,
            'topic' => 'Cancelled group review',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->subDay()->addHour(),
            'location' => 'Library',
            'status' => 'cancelled',
        ]);
        $newStart = now()->addDays(2);

        $this->actingAs($host, 'sanctum')
            ->patchJson("/api/v1/sessions/{$session->id}", [
                'status' => 'pending',
                'topic' => 'Rescheduled group review',
                'starts_at' => $newStart->toIso8601String(),
                'ends_at' => $newStart->copy()->addHour()->toIso8601String(),
                'location' => 'Study hall',
            ])
            ->assertOk()
            ->assertJsonPath('data.session.status', 'pending');

        $this->assertDatabaseHas('activity_notifications', [
            'user_id' => $member->id,
            'type' => 'session_invitation',
            'message' => "{$host->name} rescheduled Rescheduled group review. Please review and respond to the invitation.",
        ]);

        $this->actingAs($member, 'sanctum')
            ->patchJson("/api/v1/sessions/{$session->id}", ['status' => 'confirmed'])
            ->assertOk()
            ->assertJsonPath('data.session.status', 'confirmed');
    }
}
