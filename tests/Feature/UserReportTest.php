<?php

namespace Tests\Feature;

use App\Models\BuddyRequest;
use App\Models\UserReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_accepted_buddy_can_submit_a_report_for_moderation(): void
    {
        $reporter = User::factory()->create();
        $reportedUser = User::factory()->create();
        BuddyRequest::create([
            'requester_id' => $reporter->id,
            'recipient_id' => $reportedUser->id,
            'status' => 'accepted',
        ]);

        $this->actingAs($reporter, 'sanctum')
            ->postJson('/api/v1/reports/users', [
                'reported_user_id' => $reportedUser->id,
                'reason' => 'harassment',
                'details' => 'Repeated unwanted messages.',
            ])
            ->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('user_reports', [
            'reporter_user_id' => $reporter->id,
            'reported_user_id' => $reportedUser->id,
            'reason' => 'harassment',
            'details' => 'Repeated unwanted messages.',
            'status' => 'pending',
        ]);
        $this->assertSame(['harassment'], UserReport::query()->firstOrFail()->reasons);
    }

    public function test_accepted_buddy_can_submit_multiple_report_issues(): void
    {
        $reporter = User::factory()->create();
        $reportedUser = User::factory()->create();
        BuddyRequest::create([
            'requester_id' => $reporter->id,
            'recipient_id' => $reportedUser->id,
            'status' => 'accepted',
        ]);

        $this->actingAs($reporter, 'sanctum')
            ->postJson('/api/v1/reports/users', [
                'reported_user_id' => $reportedUser->id,
                'reasons' => ['harassment', 'privacy_or_safety'],
                'details' => 'Repeated unwanted messages.',
            ])
            ->assertCreated()
            ->assertJsonPath('success', true);

        $report = UserReport::query()->firstOrFail();
        $this->assertSame(['harassment', 'privacy_or_safety'], $report->reasons);
        $this->assertSame('harassment', $report->reason);
    }

    public function test_only_accepted_buddies_can_be_reported_and_reason_is_required(): void
    {
        $reporter = User::factory()->create();
        $otherUser = User::factory()->create();

        $this->actingAs($reporter, 'sanctum')
            ->postJson('/api/v1/reports/users', [
                'reported_user_id' => $otherUser->id,
                'reason' => 'harassment',
            ])
            ->assertForbidden();

        BuddyRequest::create([
            'requester_id' => $reporter->id,
            'recipient_id' => $otherUser->id,
            'status' => 'accepted',
        ]);

        $this->actingAs($reporter, 'sanctum')
            ->postJson('/api/v1/reports/users', [
                'reported_user_id' => $otherUser->id,
                'reasons' => ['harassment', 'not_a_valid_reason'],
            ])
            ->assertUnprocessable();

        $this->assertDatabaseCount('user_reports', 0);
    }

    public function test_report_requires_at_least_one_reason(): void
    {
        $reporter = User::factory()->create();
        $reportedUser = User::factory()->create();
        BuddyRequest::create([
            'requester_id' => $reporter->id,
            'recipient_id' => $reportedUser->id,
            'status' => 'accepted',
        ]);

        $this->actingAs($reporter, 'sanctum')
            ->postJson('/api/v1/reports/users', [
                'reported_user_id' => $reportedUser->id,
                'reasons' => [],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reasons']);
    }

    public function test_reporter_can_view_update_and_withdraw_their_report(): void
    {
        $reporter = User::factory()->create();
        $reportedUser = User::factory()->create();
        BuddyRequest::create([
            'requester_id' => $reporter->id,
            'recipient_id' => $reportedUser->id,
            'status' => 'accepted',
        ]);
        $this->actingAs($reporter, 'sanctum');

        $this->postJson('/api/v1/reports/users', [
            'reported_user_id' => $reportedUser->id,
            'reasons' => ['harassment', 'other'],
            'details' => 'Initial report details.',
        ])->assertCreated();

        $this->getJson("/api/v1/reports/users/{$reportedUser->id}")
            ->assertOk()
            ->assertJsonPath('data.report.reasons', ['harassment', 'other'])
            ->assertJsonPath('data.report.details', 'Initial report details.');

        $this->putJson("/api/v1/reports/users/{$reportedUser->id}", [
            'reasons' => ['privacy_or_safety'],
            'details' => 'Updated report details.',
        ])->assertOk()
            ->assertJsonPath('data.report.reasons', ['privacy_or_safety'])
            ->assertJsonPath('data.report.details', 'Updated report details.');

        $this->assertDatabaseHas('user_reports', [
            'reporter_user_id' => $reporter->id,
            'reported_user_id' => $reportedUser->id,
            'reason' => 'privacy_or_safety',
            'details' => 'Updated report details.',
            'status' => 'pending',
        ]);

        $this->deleteJson("/api/v1/reports/users/{$reportedUser->id}")
            ->assertOk()
            ->assertJsonPath('success', true);
        $this->assertDatabaseMissing('user_reports', [
            'reporter_user_id' => $reporter->id,
            'reported_user_id' => $reportedUser->id,
        ]);
    }

    public function test_report_endpoints_only_expose_the_authenticated_users_own_report(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $reportedUser = User::factory()->create();
        BuddyRequest::create([
            'requester_id' => $owner->id,
            'recipient_id' => $reportedUser->id,
            'status' => 'accepted',
        ]);
        $report = UserReport::create([
            'reporter_user_id' => $owner->id,
            'reported_user_id' => $reportedUser->id,
            'reason' => 'harassment',
            'reasons' => ['harassment'],
            'details' => 'Private report details.',
            'status' => 'pending',
        ]);

        $this->actingAs($otherUser, 'sanctum')
            ->getJson("/api/v1/reports/users/{$reportedUser->id}")
            ->assertOk()
            ->assertJsonPath('data.report', null);

        $this->putJson("/api/v1/reports/users/{$reportedUser->id}", [
            'reasons' => ['other'],
            'details' => 'Changed',
        ])->assertNotFound();
        $this->deleteJson("/api/v1/reports/users/{$reportedUser->id}")
            ->assertNotFound();
        $this->assertDatabaseHas('user_reports', ['id' => $report->id]);
    }
}
