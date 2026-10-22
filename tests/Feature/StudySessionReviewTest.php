<?php

namespace Tests\Feature;

use App\Models\BuddyRequest;
use App\Models\StudySession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudySessionReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_matched_users_can_schedule_and_complete_a_session_without_reviewing_it(): void
    {
        $host = User::factory()->create();
        $participant = User::factory()->create();
        $participant->profile()->create([
            'institution' => 'SNSU',
            'profile_photo_url' => 'https://example.test/buddy.png',
        ]);
        BuddyRequest::create([
            'requester_id' => $host->id,
            'recipient_id' => $participant->id,
            'status' => 'accepted',
        ]);

        $this->actingAs($host, 'sanctum')
            ->postJson('/api/v1/sessions', [
                'participant_user_id' => $participant->id,
                'topic' => 'Data Structures',
                'starts_at' => now()->addDay()->toIso8601String(),
                'ends_at' => now()->addDay()->addMinutes(30)->toIso8601String(),
                'location' => 'Library',
            ])
            ->assertCreated()
            ->assertJsonPath('data.session.status', 'pending')
            ->assertJsonPath('data.session.other_user_profile_photo_url', 'https://example.test/buddy.png');

        $session = StudySession::query()->firstOrFail();
        $this->actingAs($participant, 'sanctum')
            ->patchJson("/api/v1/sessions/{$session->id}", ['status' => 'confirmed'])
            ->assertOk()
            ->assertJsonPath('data.session.status', 'confirmed');
        $this->travelTo($session->starts_at->addHour());

        $this->actingAs($participant, 'sanctum')
            ->getJson('/api/v1/sessions')
            ->assertOk()
            ->assertJsonPath('data.sessions.0.is_past', true)
            ->assertJsonPath('data.sessions.0.status', 'confirmed');

        $this->actingAs($participant, 'sanctum')
            ->patchJson("/api/v1/sessions/{$session->id}", ['status' => 'confirmed'])
            ->assertForbidden();

        $this->actingAs($participant, 'sanctum')
            ->patchJson("/api/v1/sessions/{$session->id}", ['status' => 'completed'])
            ->assertForbidden();

        $this->actingAs($host, 'sanctum')
            ->patchJson("/api/v1/sessions/{$session->id}", ['status' => 'completed'])
            ->assertOk();

        $this->actingAs($host, 'sanctum')
            ->patchJson("/api/v1/sessions/{$session->id}", [
                'topic' => 'Data Structures - Rescheduled',
                'starts_at' => now()->addDay()->toIso8601String(),
                'ends_at' => now()->addDay()->addMinutes(30)->toIso8601String(),
                'location' => 'Library',
            ])
            ->assertOk()
            ->assertJsonPath('data.session.status', 'confirmed')
            ->assertJsonPath('data.session.is_past', false);

        $session->refresh();
        $this->travelTo($session->starts_at->addHour());
        $this->actingAs($host, 'sanctum')
            ->patchJson("/api/v1/sessions/{$session->id}", ['status' => 'completed'])
            ->assertOk();

        $this->actingAs($participant, 'sanctum')
            ->deleteJson("/api/v1/sessions/{$session->id}")
            ->assertNotFound();
        $this->actingAs($host, 'sanctum')
            ->deleteJson("/api/v1/sessions/{$session->id}")
            ->assertOk();
        $this->assertDatabaseMissing('study_sessions', ['id' => $session->id]);
        $this->assertDatabaseCount('study_session_reviews', 0);
    }

    public function test_accepted_buddies_can_rate_each_other_after_a_completed_session_and_update_their_rating(): void
    {
        $reviewer = User::factory()->create();
        $buddy = User::factory()->create();
        BuddyRequest::create([
            'requester_id' => $reviewer->id,
            'recipient_id' => $buddy->id,
            'status' => 'accepted',
        ]);

        $this->actingAs($reviewer, 'sanctum')
            ->postJson('/api/v1/reviews', [
                'reviewed_user_id' => $buddy->id,
                'rating' => 5,
            ])
            ->assertForbidden()
            ->assertJsonPath('message', 'Complete at least one study session with this buddy before rating them.');

        StudySession::create([
            'host_user_id' => $reviewer->id,
            'participant_user_id' => $buddy->id,
            'study_group_id' => null,
            'topic' => 'Previous session',
            'starts_at' => now()->subHours(2),
            'ends_at' => now()->subHour(),
            'location' => 'Library',
            'status' => 'completed',
        ]);

        $this->actingAs($reviewer, 'sanctum')
            ->postJson('/api/v1/reviews', [
                'reviewed_user_id' => $buddy->id,
                'rating' => 5,
                'comment' => 'A supportive study buddy.',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('buddy_reviews', [
            'reviewer_user_id' => $reviewer->id,
            'reviewed_user_id' => $buddy->id,
            'rating' => 5,
        ]);

        $this->actingAs($reviewer, 'sanctum')
            ->getJson("/api/v1/reviews?review_user_id={$buddy->id}")
            ->assertOk()
            ->assertJsonPath('data.average_rating', 5)
            ->assertJsonPath('data.ratings_count', 1)
            ->assertJsonPath('data.my_review.rating', 5)
            ->assertJsonPath('data.reviews.0.comment', 'A supportive study buddy.');

        $this->actingAs($buddy, 'sanctum')
            ->getJson('/api/v1/reviews')
            ->assertOk()
            ->assertJsonPath('data.reviews.0.reviewer_name', $reviewer->name)
            ->assertJsonPath('data.reviewable_buddies.0.id', $reviewer->id);

        $this->actingAs($reviewer, 'sanctum')
            ->postJson('/api/v1/reviews', [
                'reviewed_user_id' => $buddy->id,
                'rating' => 3,
                'comment' => 'Updated overall feedback.',
            ])
            ->assertOk();

        $this->assertDatabaseCount('buddy_reviews', 1);
        $this->assertDatabaseHas('buddy_reviews', [
            'reviewer_user_id' => $reviewer->id,
            'reviewed_user_id' => $buddy->id,
            'rating' => 3,
            'comment' => 'Updated overall feedback.',
        ]);
    }

    public function test_only_an_accepted_buddy_can_be_rated(): void
    {
        $reviewer = User::factory()->create();
        $otherUser = User::factory()->create();

        $this->actingAs($reviewer, 'sanctum')
            ->postJson('/api/v1/reviews', [
                'reviewed_user_id' => $otherUser->id,
                'rating' => 5,
            ])
            ->assertForbidden();

        $this->actingAs($reviewer, 'sanctum')
            ->postJson('/api/v1/reviews', [
                'reviewed_user_id' => $reviewer->id,
                'rating' => 5,
            ])
            ->assertUnprocessable();
    }

    public function test_unmatched_students_cannot_schedule_sessions(): void
    {
        $host = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($host, 'sanctum')
            ->postJson('/api/v1/sessions', [
                'participant_user_id' => $other->id,
                'topic' => 'Study',
                'starts_at' => now()->addDay()->toIso8601String(),
                'ends_at' => now()->addDay()->addMinutes(30)->toIso8601String(),
                'location' => 'Library',
            ])
            ->assertForbidden();
    }
}
