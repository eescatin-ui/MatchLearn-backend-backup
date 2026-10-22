<?php

namespace Tests\Feature;

use App\Models\BuddyRequest;
use App\Models\Profile;
use App\Models\StudyMatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DirectMessageAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_matched_participants_can_start_and_access_direct_messages(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $outsider = User::factory()->create();
        $firstPhoto = 'https://matchlearn.test/storage/profile-photos/first.png';
        $secondPhoto = 'https://matchlearn.test/storage/profile-photos/second.png';
        Profile::create(['user_id' => $first->id, 'institution' => 'SNSU', 'profile_photo_url' => $firstPhoto]);
        Profile::create(['user_id' => $second->id, 'institution' => 'SNSU', 'profile_photo_url' => $secondPhoto]);

        StudyMatch::create([
            'user_id' => $first->id,
            'matched_user_id' => $second->id,
            'compatibility_score' => 80,
            'shared_course_count' => 1,
            'reasons' => [],
        ]);

        BuddyRequest::create([
            'requester_id' => $first->id,
            'recipient_id' => $second->id,
            'status' => 'accepted',
        ]);

        $conversationResponse = $this->actingAs($first, 'sanctum')
            ->postJson('/api/v1/messages/direct-conversations', [
                'matched_user_id' => $second->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.conversation.profile_photo_url', $secondPhoto);

        $conversationId = $conversationResponse->json('data.conversation.id');

        $this->actingAs($first, 'sanctum')
            ->getJson('/api/v1/messages')
            ->assertOk()
            ->assertJsonPath('data.conversations.0.profile_photo_url', $secondPhoto);

        $this->actingAs($first, 'sanctum')
            ->postJson("/api/v1/messages/direct-conversations/{$conversationId}", ['content' => 'Hello'])
            ->assertCreated();

        $this->actingAs($second, 'sanctum')
            ->getJson("/api/v1/messages/direct-conversations/{$conversationId}")
            ->assertOk()
            ->assertJsonPath('data.messages.0.content', 'Hello')
            ->assertJsonPath('data.messages.0.sender', 'them')
            ->assertJsonPath('data.messages.0.user.id', $first->id)
            ->assertJsonPath('data.messages.0.user.name', $first->name)
            ->assertJsonPath('data.messages.0.user.profile_photo_url', $firstPhoto);

        $this->actingAs($second, 'sanctum')
            ->postJson("/api/v1/messages/direct-conversations/{$conversationId}", ['content' => 'Hi there'])
            ->assertCreated();

        $this->actingAs($first, 'sanctum')
            ->getJson("/api/v1/messages/direct-conversations/{$conversationId}")
            ->assertOk()
            ->assertJsonPath('data.messages.0.sender', 'me')
            ->assertJsonPath('data.messages.1.sender', 'them')
            ->assertJsonPath('data.messages.1.user.name', $second->name);

        $this->actingAs($outsider, 'sanctum')
            ->getJson("/api/v1/messages/direct-conversations/{$conversationId}")
            ->assertNotFound();

        $this->actingAs($outsider, 'sanctum')
            ->postJson('/api/v1/messages/direct-conversations', ['matched_user_id' => $first->id])
            ->assertForbidden();
    }

    public function test_recommended_students_cannot_message_until_buddy_request_is_accepted(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        StudyMatch::create([
            'user_id' => $first->id,
            'matched_user_id' => $second->id,
            'compatibility_score' => 80,
            'shared_course_count' => 1,
            'reasons' => [],
        ]);

        $this->actingAs($first, 'sanctum')
            ->postJson('/api/v1/messages/direct-conversations', ['matched_user_id' => $second->id])
            ->assertForbidden()
            ->assertJsonPath('message', 'You can only start a conversation with an accepted study buddy.');
    }
}
