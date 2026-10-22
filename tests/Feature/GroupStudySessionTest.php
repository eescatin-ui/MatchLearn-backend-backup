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
}
