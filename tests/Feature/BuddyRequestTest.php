<?php

namespace Tests\Feature;

use App\Models\BuddyRequest;
use App\Models\BuddyReview;
use App\Models\Department;
use App\Models\LearningStyle;
use App\Models\Profile;
use App\Models\Program;
use App\Models\StudyMatch;
use App\Models\User;
use App\Services\MatchingService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuddyRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_recommended_student_can_request_and_recipient_can_accept(): void
    {
        $this->seed(DatabaseSeeder::class);
        $requester = User::factory()->create();
        $recipient = User::factory()->create();
        $department = Department::query()->firstOrFail();
        $program = Program::query()->where('department_id', $department->id)->firstOrFail();
        $style = LearningStyle::query()->firstOrFail();
        foreach ([$requester, $recipient] as $student) {
            Profile::create([
                'user_id' => $student->id,
                'institution' => 'SNSU',
                'department_id' => $department->id,
                'program_id' => $program->id,
                'year_level' => 2,
                'learning_style_id' => $style->id,
            ]);
        }
        $photoUrl = 'https://matchlearn.test/storage/profile-photos/recipient.png';
        $recipient->profile->update([
            'profile_photo_url' => $photoUrl,
            'gender' => 'Female',
            'marital_status' => 'Single',
            'phone_number' => '+63 912 345 6789',
            'address' => '123 Main Street, Surigao City',
        ]);
        StudyMatch::create([
            'user_id' => $requester->id,
            'matched_user_id' => $recipient->id,
            'compatibility_score' => 80,
            'shared_course_count' => 1,
            'reasons' => [],
        ]);
        BuddyReview::create([
            'reviewer_user_id' => $requester->id,
            'reviewed_user_id' => $recipient->id,
            'rating' => 4,
            'comment' => 'Helpful buddy.',
        ]);

        $this->actingAs($requester, 'sanctum')
            ->getJson('/api/v1/matches')
            ->assertOk()
            ->assertJsonPath('data.matches.0.matched_user.average_rating', 4)
            ->assertJsonPath('data.matches.0.matched_user.ratings_count', 1)
            ->assertJsonPath('data.matches.0.matched_user.profile.profile_photo_url', $photoUrl)
            ->assertJsonPath('data.matches.0.matched_user.profile.gender', 'Female')
            ->assertJsonPath('data.matches.0.matched_user.profile.marital_status', 'Single')
            ->assertJsonPath('data.matches.0.matched_user.profile.phone_number', null)
            ->assertJsonPath('data.matches.0.matched_user.profile.address', null);

        $recipient->profile->update([
            'phone_number_public' => true,
            'address_public' => true,
        ]);
        $this->actingAs($requester, 'sanctum')
            ->getJson('/api/v1/matches')
            ->assertOk()
            ->assertJsonPath('data.matches.0.matched_user.profile.phone_number', '+63 912 345 6789')
            ->assertJsonPath('data.matches.0.matched_user.profile.address', '123 Main Street, Surigao City');

        $response = $this->actingAs($requester, 'sanctum')
            ->postJson('/api/v1/buddies/requests', ['user_id' => $recipient->id])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending_sent');
        $requestId = $response->json('data.request_id');
        $this->assertDatabaseHas('activity_notifications', [
            'user_id' => $recipient->id,
            'type' => 'buddy_request',
        ]);

        $this->actingAs($recipient, 'sanctum')
            ->getJson('/api/v1/buddies')
            ->assertOk()
            ->assertJsonPath('data.incoming_requests.0.user.id', $requester->id);

        $this->actingAs($requester, 'sanctum')
            ->getJson('/api/v1/buddies')
            ->assertOk()
            ->assertJsonPath('data.outgoing_requests.0.user.profile_photo_url', $photoUrl);

        $this->actingAs($recipient, 'sanctum')
            ->patchJson("/api/v1/buddies/requests/{$requestId}", ['action' => 'accept'])
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted');

        $this->actingAs($requester, 'sanctum')
            ->getJson('/api/v1/buddies')
            ->assertOk()
            ->assertJsonPath('data.buddies.0.id', $recipient->id)
            ->assertJsonPath('data.buddies.0.profile_photo_url', $photoUrl)
            ->assertJsonPath('data.buddies.0.average_rating', 4)
            ->assertJsonPath('data.buddies.0.ratings_count', 1);
        $this->actingAs($requester, 'sanctum')
            ->getJson("/api/v1/buddies/{$recipient->id}")
            ->assertOk()
            ->assertJsonPath('data.buddy.profile.gender', 'Female')
            ->assertJsonPath('data.buddy.profile.marital_status', 'Single')
            ->assertJsonPath('data.buddy.profile.phone_number', '+63 912 345 6789')
            ->assertJsonPath('data.buddy.profile.address', '123 Main Street, Surigao City');

        $this->actingAs($recipient, 'sanctum')
            ->getJson('/api/v1/buddies')
            ->assertOk()
            ->assertJsonPath('data.buddies.0.id', $requester->id);

        $this->assertDatabaseHas('buddy_requests', [
            'id' => $requestId,
            'status' => 'accepted',
        ]);
        $this->assertDatabaseHas('activity_notifications', [
            'user_id' => $requester->id,
            'type' => 'buddy_request_accepted',
        ]);

        $this->assertEmpty(app(MatchingService::class)->getMatchesForUser($requester));
        $this->assertDatabaseMissing('matches', [
            'user_id' => $requester->id,
            'matched_user_id' => $recipient->id,
        ]);
    }

    public function test_only_recommended_students_can_receive_a_buddy_request(): void
    {
        $requester = User::factory()->create();
        $recipient = User::factory()->create();

        $this->actingAs($requester, 'sanctum')
            ->postJson('/api/v1/buddies/requests', ['user_id' => $recipient->id])
            ->assertForbidden();

        $this->assertDatabaseCount('buddy_requests', 0);
    }

    public function test_only_recipient_can_accept_or_decline_a_request(): void
    {
        $requester = User::factory()->create();
        $recipient = User::factory()->create();
        $outsider = User::factory()->create();
        $buddyRequest = BuddyRequest::create([
            'requester_id' => $requester->id,
            'recipient_id' => $recipient->id,
            'status' => 'pending',
        ]);

        $this->actingAs($outsider, 'sanctum')
            ->patchJson("/api/v1/buddies/requests/{$buddyRequest->id}", ['action' => 'accept'])
            ->assertNotFound();

        $this->assertDatabaseHas('buddy_requests', [
            'id' => $buddyRequest->id,
            'status' => 'pending',
        ]);
    }
}
