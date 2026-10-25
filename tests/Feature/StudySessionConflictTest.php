<?php

namespace Tests\Feature;

use App\Models\BuddyRequest;
use App\Models\StudySession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudySessionConflictTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_or_confirmed_session_overlaps_are_rejected_for_any_participant(): void
    {
        $host = User::factory()->create();
        $buddy = User::factory()->create();
        $existingHost = User::factory()->create();

        BuddyRequest::create([
            'requester_id' => $host->id,
            'recipient_id' => $buddy->id,
            'status' => 'accepted',
        ]);
        BuddyRequest::create([
            'requester_id' => $existingHost->id,
            'recipient_id' => $buddy->id,
            'status' => 'accepted',
        ]);

        $existingStart = now()->addDays(2)->startOfDay()->addHours(10);
        StudySession::create([
            'host_user_id' => $existingHost->id,
            'participant_user_id' => $buddy->id,
            'topic' => 'Existing session',
            'starts_at' => $existingStart,
            'ends_at' => $existingStart->copy()->addHour(),
            'location' => 'Library',
            'status' => 'pending',
        ]);

        $this->actingAs($host, 'sanctum')
            ->postJson('/api/v1/sessions', [
                'participant_user_id' => $buddy->id,
                'topic' => 'Overlapping session',
                'starts_at' => $existingStart->copy()->addMinutes(30)->toIso8601String(),
                'ends_at' => $existingStart->copy()->addMinutes(90)->toIso8601String(),
                'location' => 'Library',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'You or another session participant already has a pending or confirmed session during this time.');

        $adjacentSessionResponse = $this->actingAs($host, 'sanctum')
            ->postJson('/api/v1/sessions', [
                'participant_user_id' => $buddy->id,
                'topic' => 'Following session',
                'starts_at' => $existingStart->copy()->addHour()->toIso8601String(),
                'ends_at' => $existingStart->copy()->addHours(2)->toIso8601String(),
                'location' => 'Library',
            ])
            ->assertCreated();

        $sessionId = $adjacentSessionResponse->json('data.session.id');
        $this->actingAs($host, 'sanctum')
            ->patchJson("/api/v1/sessions/{$sessionId}", [
                'topic' => 'Rescheduled into a conflict',
                'starts_at' => $existingStart->copy()->addMinutes(45)->toIso8601String(),
                'ends_at' => $existingStart->copy()->addMinutes(75)->toIso8601String(),
                'location' => 'Library',
            ])
            ->assertUnprocessable();

        $this->assertDatabaseCount('study_sessions', 2);
    }
}
