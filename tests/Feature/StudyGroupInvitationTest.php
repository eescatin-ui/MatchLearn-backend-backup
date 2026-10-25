<?php

namespace Tests\Feature;

use App\Models\BuddyRequest;
use App\Models\StudyGroup;
use App\Models\StudyMatch;
use App\Models\StudySession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudyGroupInvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_group_admin_can_invite_an_accepted_buddy_and_buddy_can_accept(): void
    {
        $admin = User::factory()->create();
        $buddy = User::factory()->create();
        $groupId = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/groups', ['name' => 'Study group', 'course' => 'Mathematics'])
            ->assertCreated()
            ->json('data.group.id');
        BuddyRequest::create([
            'requester_id' => $admin->id,
            'recipient_id' => $buddy->id,
            'status' => 'accepted',
        ]);

        $invitation = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/groups/{$groupId}/invitations", ['user_id' => $buddy->id])
            ->assertCreated()
            ->assertJsonPath('data.status', 'invited');
        $requestId = $invitation->json('data.request_id');

        $this->assertDatabaseHas('activity_notifications', [
            'user_id' => $buddy->id,
            'type' => 'group_invitation',
        ]);
        $this->actingAs($buddy, 'sanctum')
            ->patchJson("/api/v1/groups/{$groupId}/invitations/{$requestId}", ['action' => 'accept'])
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted');

        $this->assertDatabaseHas('study_group_members', [
            'study_group_id' => $groupId,
            'user_id' => $buddy->id,
            'role' => 'member',
        ]);
        $this->assertDatabaseHas('study_group_join_requests', [
            'id' => $requestId,
            'status' => 'accepted',
        ]);
        $this->assertDatabaseHas('activity_notifications', [
            'user_id' => $admin->id,
            'type' => 'group_invitation_accepted',
        ]);
        $this->assertDatabaseHas('activity_notifications', [
            'user_id' => $buddy->id,
            'type' => 'group_invitation_accepted',
        ]);
    }

    public function test_only_group_admin_can_invite_and_only_accepted_buddies_are_invitable(): void
    {
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $otherUser = User::factory()->create();
        $groupId = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/groups', ['name' => 'Private group', 'course' => 'Mathematics'])
            ->assertCreated()
            ->json('data.group.id');
        StudyGroup::findOrFail($groupId)->members()->attach($member->id, ['role' => 'member']);

        $this->actingAs($member, 'sanctum')
            ->postJson("/api/v1/groups/{$groupId}/invitations", ['user_id' => $otherUser->id])
            ->assertForbidden();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/groups/{$groupId}/invitations", ['user_id' => $otherUser->id])
            ->assertUnprocessable();
    }

    public function test_group_profile_shows_confirmed_and_completed_session_count(): void
    {
        $admin = User::factory()->create();
        $groupId = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/groups', ['name' => 'Session count', 'course' => 'Mathematics'])
            ->assertCreated()
            ->json('data.group.id');

        foreach (['confirmed', 'completed', 'pending', 'cancelled'] as $status) {
            StudySession::create([
                'host_user_id' => $admin->id,
                'participant_user_id' => null,
                'study_group_id' => $groupId,
                'topic' => 'Group session',
                'starts_at' => now(),
                'ends_at' => now()->addHour(),
                'location' => 'Library',
                'status' => $status,
            ]);
        }

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/groups/{$groupId}")
            ->assertOk()
            ->assertJsonPath('data.group.total_sessions', 2);
    }

    public function test_buddy_profile_includes_the_saved_compatibility_score(): void
    {
        $user = User::factory()->create();
        $buddy = User::factory()->create();
        BuddyRequest::create([
            'requester_id' => $user->id,
            'recipient_id' => $buddy->id,
            'status' => 'accepted',
        ]);
        StudyMatch::create([
            'user_id' => $user->id,
            'matched_user_id' => $buddy->id,
            'compatibility_score' => 86,
            'shared_course_count' => 1,
            'reasons' => [],
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/buddies/{$buddy->id}")
            ->assertOk()
            ->assertJsonPath('data.buddy.compatibility_score', 86);
    }
}
