<?php

namespace Tests\Feature;

use App\Models\BuddyRequest;
use App\Models\DirectConversation;
use App\Models\StudyMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnreadMessageCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_unread_group_messages_count_until_the_recipient_opens_the_conversation(): void
    {
        $admin = User::factory()->create();
        $reader = User::factory()->create();
        $sender = User::factory()->create();
        $secondSender = User::factory()->create();
        $groupId = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/groups', ['name' => 'Unread checks', 'course' => 'IT 220'])
            ->assertCreated()
            ->json('data.group.id');

        foreach ([$reader, $sender, $secondSender] as $member) {
            $requestId = $this->actingAs($member, 'sanctum')
                ->postJson("/api/v1/groups/{$groupId}/join")
                ->assertCreated()
                ->json('data.request_id');

            $this->actingAs($admin, 'sanctum')
                ->patchJson("/api/v1/groups/{$groupId}/join-requests/{$requestId}", ['action' => 'accept'])
                ->assertOk();
        }

        $this->actingAs($sender, 'sanctum')
            ->postJson('/api/v1/messages', [
                'group_id' => $groupId,
                'content' => 'Please review this topic.',
            ])
            ->assertCreated();
        $this->actingAs($sender, 'sanctum')
            ->postJson('/api/v1/messages', [
                'group_id' => $groupId,
                'content' => 'One more detail.',
            ])
            ->assertCreated();
        $this->actingAs($secondSender, 'sanctum')
            ->postJson('/api/v1/messages', [
                'group_id' => $groupId,
                'content' => 'I have an update too.',
            ])
            ->assertCreated();

        $this->actingAs($reader, 'sanctum')
            ->getJson('/api/v1/messages')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 3)
            ->assertJsonPath('data.conversations.0.unread_count', 3);

        $this->actingAs($reader, 'sanctum')
            ->getJson("/api/v1/messages/{$groupId}")
            ->assertOk();
        $this->actingAs($reader, 'sanctum')
            ->getJson('/api/v1/messages')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/messages')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 3);
    }

    public function test_unread_direct_messages_count_until_the_recipient_opens_the_conversation(): void
    {
        $reader = User::factory()->create();
        $sender = User::factory()->create();
        BuddyRequest::create([
            'requester_id' => $reader->id,
            'recipient_id' => $sender->id,
            'status' => 'accepted',
        ]);
        $conversation = DirectConversation::create([
            'user_one_id' => min($reader->id, $sender->id),
            'user_two_id' => max($reader->id, $sender->id),
        ]);
        StudyMessage::create([
            'direct_conversation_id' => $conversation->id,
            'user_id' => $sender->id,
            'content' => 'Ready to study?',
        ]);

        $this->actingAs($reader, 'sanctum')
            ->getJson('/api/v1/messages')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1);
        $this->actingAs($reader, 'sanctum')
            ->getJson("/api/v1/messages/direct-conversations/{$conversation->id}")
            ->assertOk();
        $this->actingAs($reader, 'sanctum')
            ->getJson('/api/v1/messages')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);
    }
}
