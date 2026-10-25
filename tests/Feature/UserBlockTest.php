<?php

namespace Tests\Feature;

use App\Models\BuddyRequest;
use App\Models\DirectConversation;
use App\Models\Profile;
use App\Models\StudyMatch;
use App\Models\StudyMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserBlockTest extends TestCase
{
    use RefreshDatabase;

    public function test_blocking_removes_connection_match_and_direct_conversation_access(): void
    {
        $blocker = User::factory()->create();
        $blocked = User::factory()->create();
        Profile::create(['user_id' => $blocker->id, 'institution' => 'SNSU']);
        Profile::create(['user_id' => $blocked->id, 'institution' => 'SNSU']);
        $buddyRequest = BuddyRequest::create([
            'requester_id' => $blocker->id,
            'recipient_id' => $blocked->id,
            'status' => 'accepted',
        ]);
        StudyMatch::create([
            'user_id' => $blocker->id,
            'matched_user_id' => $blocked->id,
            'compatibility_score' => 80,
            'shared_course_count' => 1,
            'reasons' => [],
        ]);
        $conversation = DirectConversation::create([
            'user_one_id' => min($blocker->id, $blocked->id),
            'user_two_id' => max($blocker->id, $blocked->id),
        ]);
        StudyMessage::create([
            'direct_conversation_id' => $conversation->id,
            'user_id' => $blocker->id,
            'content' => 'Hello',
        ]);

        $this->actingAs($blocker, 'sanctum')
            ->postJson('/api/v1/blocks', ['user_id' => $blocked->id])
            ->assertCreated();

        $this->assertDatabaseHas('user_blocks', [
            'blocker_user_id' => $blocker->id,
            'blocked_user_id' => $blocked->id,
        ]);
        $this->assertDatabaseMissing('buddy_requests', ['id' => $buddyRequest->id]);
        $this->assertDatabaseMissing('matches', [
            'user_id' => $blocker->id,
            'matched_user_id' => $blocked->id,
        ]);
        $this->assertDatabaseMissing('direct_conversations', ['id' => $conversation->id]);
        $this->assertDatabaseMissing('study_group_messages', ['direct_conversation_id' => $conversation->id]);

        $this->actingAs($blocker, 'sanctum')
            ->getJson('/api/v1/blocks')
            ->assertOk()
            ->assertJsonPath('data.blocked_users.0.user_id', $blocked->id);

        $this->actingAs($blocker, 'sanctum')
            ->getJson('/api/v1/buddies')
            ->assertOk()
            ->assertJsonPath('data.buddies', [])
            ->assertJsonPath('data.incoming_requests', [])
            ->assertJsonPath('data.outgoing_requests', []);

        $this->actingAs($blocker, 'sanctum')
            ->getJson('/api/v1/matches')
            ->assertOk()
            ->assertJsonPath('data.matches', []);

        $this->actingAs($blocked, 'sanctum')
            ->postJson('/api/v1/messages/direct-conversations', ['matched_user_id' => $blocker->id])
            ->assertForbidden();

        $this->actingAs($blocker, 'sanctum')
            ->postJson('/api/v1/blocks', ['user_id' => $blocker->id])
            ->assertUnprocessable();

        $this->actingAs($blocker, 'sanctum')
            ->deleteJson("/api/v1/blocks/{$blocked->id}")
            ->assertOk();

        $this->actingAs($blocker, 'sanctum')
            ->getJson('/api/v1/blocks')
            ->assertOk()
            ->assertJsonPath('data.blocked_users', []);
    }
}
