<?php

namespace Tests\Feature;

use App\Models\BuddyRequest;
use App\Models\BuddyReview;
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
        BuddyReview::create([
            'reviewer_user_id' => $first->id,
            'reviewed_user_id' => $second->id,
            'rating' => 5,
        ]);
        BuddyReview::create([
            'reviewer_user_id' => $second->id,
            'reviewed_user_id' => $first->id,
            'rating' => 3,
        ]);

        $conversationResponse = $this->actingAs($first, 'sanctum')
            ->postJson('/api/v1/messages/direct-conversations', [
                'matched_user_id' => $second->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.conversation.profile_photo_url', $secondPhoto)
            ->assertJsonPath('data.conversation.average_rating', 5)
            ->assertJsonPath('data.conversation.ratings_count', 1);

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
            ->assertJsonPath('data.messages.0.user.profile_photo_url', $firstPhoto)
            ->assertJsonPath('data.messages.0.user.average_rating', 3)
            ->assertJsonPath('data.messages.0.user.ratings_count', 1);

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

    public function test_retries_with_the_same_client_message_id_do_not_duplicate_a_direct_message(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        BuddyRequest::create([
            'requester_id' => $first->id,
            'recipient_id' => $second->id,
            'status' => 'accepted',
        ]);
        $conversationId = $this->actingAs($first, 'sanctum')
            ->postJson('/api/v1/messages/direct-conversations', ['matched_user_id' => $second->id])
            ->assertCreated()
            ->json('data.conversation.id');

        $firstResponse = $this->actingAs($first, 'sanctum')
            ->postJson("/api/v1/messages/direct-conversations/{$conversationId}", [
                'content' => 'Please review this topic.',
                'client_message_id' => 'retry-token-123',
            ])
            ->assertCreated();
        $retryResponse = $this->actingAs($first, 'sanctum')
            ->postJson("/api/v1/messages/direct-conversations/{$conversationId}", [
                'content' => 'Please review this topic.',
                'client_message_id' => 'retry-token-123',
            ])
            ->assertCreated();

        $this->assertSame(
            $firstResponse->json('data.message.id'),
            $retryResponse->json('data.message.id')
        );
        $this->assertDatabaseCount('study_group_messages', 1);
    }

    public function test_retries_with_the_same_client_message_id_do_not_duplicate_a_group_message(): void
    {
        $member = User::factory()->create();
        $groupId = $this->actingAs($member, 'sanctum')
            ->postJson('/api/v1/groups', ['name' => 'Review group', 'course' => 'IT 213'])
            ->assertCreated()
            ->json('data.group.id');

        foreach (['First attempt', 'Retry attempt'] as $content) {
            $this->actingAs($member, 'sanctum')
                ->postJson('/api/v1/messages', [
                    'group_id' => $groupId,
                    'content' => $content,
                    'client_message_id' => 'group-retry-token-123',
                ])
                ->assertCreated();
        }

        $this->assertDatabaseCount('study_group_messages', 1);
        $this->assertDatabaseHas('study_group_messages', [
            'group_id' => $groupId,
            'content' => 'First attempt',
        ]);
    }
}
