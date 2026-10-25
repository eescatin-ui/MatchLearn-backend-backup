<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudyGroupAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_members_need_admin_approval_before_reading_or_sending_group_messages(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $createResponse = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/groups', [
            'name' => 'Study group',
            'course' => 'Computer Science',
        ])->assertCreated();

        $groupId = $createResponse->json('data.group.id');

        $this->actingAs($otherUser, 'sanctum')
            ->getJson("/api/v1/messages/{$groupId}")
            ->assertNotFound();

        $this->actingAs($otherUser, 'sanctum')
            ->postJson('/api/v1/messages', [
                'group_id' => $groupId,
                'content' => 'Hello',
            ])
            ->assertNotFound();

        $requestResponse = $this->actingAs($otherUser, 'sanctum')
            ->postJson("/api/v1/groups/{$groupId}/join")
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        $requestId = $requestResponse->json('data.request_id');
        $this->assertDatabaseHas('activity_notifications', [
            'user_id' => $owner->id,
            'type' => 'group_join_request',
        ]);
        $this->actingAs($otherUser, 'sanctum')
            ->getJson("/api/v1/messages/{$groupId}")
            ->assertNotFound();
        $this->actingAs($otherUser, 'sanctum')
            ->postJson('/api/v1/messages', [
                'group_id' => $groupId,
                'content' => 'Hello',
            ])->assertNotFound();

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/groups')
            ->assertOk()
            ->assertJsonPath('data.groups.0.join_requests.0.user_id', $otherUser->id);

        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/groups/{$groupId}/join-requests/{$requestId}", ['action' => 'accept'])
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted');
        $this->assertDatabaseHas('activity_notifications', [
            'user_id' => $otherUser->id,
            'type' => 'group_join_accepted',
        ]);

        $this->actingAs($otherUser, 'sanctum')
            ->getJson("/api/v1/messages/{$groupId}")
            ->assertOk();

        $this->actingAs($otherUser, 'sanctum')
            ->getJson('/api/v1/groups/discover')
            ->assertOk()
            ->assertJsonCount(0, 'data.groups');

        $this->actingAs($otherUser, 'sanctum')
            ->postJson('/api/v1/messages', [
                'group_id' => $groupId,
                'content' => 'Hello',
            ])
            ->assertCreated();

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/messages/{$groupId}")
            ->assertOk()
            ->assertJsonPath('data.messages.0.content', 'Hello')
            ->assertJsonPath('data.messages.0.sender', 'them')
            ->assertJsonPath('data.messages.0.user.id', $otherUser->id)
            ->assertJsonPath('data.messages.0.user.name', $otherUser->name);
    }

    public function test_only_group_admin_can_respond_to_a_join_request(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $requester = User::factory()->create();
        $groupId = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/groups', [
            'name' => 'Admin-only approvals',
            'course' => 'Mathematics',
        ])->assertCreated()->json('data.group.id');

        $requestId = $this->actingAs($requester, 'sanctum')
            ->postJson("/api/v1/groups/{$groupId}/join")
            ->assertCreated()
            ->json('data.request_id');

        $this->actingAs($member, 'sanctum')
            ->patchJson("/api/v1/groups/{$groupId}/join-requests/{$requestId}", ['action' => 'accept'])
            ->assertForbidden();

        $this->assertDatabaseHas('study_group_join_requests', [
            'id' => $requestId,
            'status' => 'pending',
        ]);
    }

    public function test_pending_requester_can_view_group_details_and_cancel_their_request(): void
    {
        $owner = User::factory()->create();
        $requester = User::factory()->create();
        $groupId = $this->actingAs($owner, 'sanctum')->postJson('/api/v1/groups', [
            'name' => 'Discoverable requested group',
            'course' => 'Mathematics',
        ])->assertCreated()->json('data.group.id');
        $this->actingAs($requester, 'sanctum')
            ->getJson("/api/v1/groups/{$groupId}")
            ->assertOk()
            ->assertJsonPath('data.group.request_status', null)
            ->assertJsonPath('data.group.role', null);

        $requestId = $this->actingAs($requester, 'sanctum')
            ->postJson("/api/v1/groups/{$groupId}/join")
            ->assertCreated()
            ->json('data.request_id');

        $this->actingAs($requester, 'sanctum')
            ->getJson('/api/v1/groups/discover')
            ->assertOk()
            ->assertJsonPath('data.groups.0.request_status', 'pending')
            ->assertJsonPath('data.groups.0.request_id', $requestId);
        $this->actingAs($requester, 'sanctum')
            ->getJson("/api/v1/groups/{$groupId}")
            ->assertOk()
            ->assertJsonPath('data.group.name', 'Discoverable requested group')
            ->assertJsonPath('data.group.request_status', 'pending')
            ->assertJsonPath('data.group.request_id', $requestId)
            ->assertJsonPath('data.group.role', null);

        $this->actingAs($requester, 'sanctum')
            ->deleteJson("/api/v1/groups/{$groupId}/join-requests")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertDatabaseHas('study_group_join_requests', [
            'id' => $requestId,
            'status' => 'cancelled',
        ]);
        $this->assertDatabaseHas('activity_notifications', [
            'user_id' => $owner->id,
            'type' => 'group_join_cancelled',
        ]);
        $this->actingAs($requester, 'sanctum')
            ->getJson("/api/v1/groups/{$groupId}")
            ->assertOk()
            ->assertJsonPath('data.group.request_status', null)
            ->assertJsonPath('data.group.role', null);
    }

    public function test_group_list_only_contains_groups_the_user_has_joined(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $this->actingAs($owner, 'sanctum')->postJson('/api/v1/groups', [
            'name' => 'Private to members',
            'course' => 'Mathematics',
        ])->assertCreated();

        $this->actingAs($otherUser, 'sanctum')
            ->getJson('/api/v1/groups')
            ->assertOk()
            ->assertJsonCount(0, 'data.groups');
    }
}
