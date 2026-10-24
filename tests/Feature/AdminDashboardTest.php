<?php

namespace Tests\Feature;

use App\Models\MatchingSetting;
use App\Models\Profile;
use App\Models\StudyGroup;
use App\Models\StudySession;
use App\Models\UserReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_sign_in_with_verified_institutional_google_account(): void
    {
        $admin = User::factory()->create([
            'email' => 'admin@ssct.edu.ph',
            'password' => 'password',
            'name' => 'Old Admin Name',
            'is_admin' => true,
        ]);

        config(['services.google.client_id' => 'matchlearn-web-client']);
        Http::fake([
            'https://oauth2.googleapis.com/tokeninfo*' => Http::response([
                'aud' => 'matchlearn-web-client',
                'iss' => 'https://accounts.google.com',
                'exp' => (string) now()->addMinutes(5)->timestamp,
                'email' => 'admin@ssct.edu.ph',
                'email_verified' => true,
                'sub' => 'google-admin-123',
                'name' => 'Google Admin Name',
            ]),
        ]);

        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('Sign in with Google')
            ->assertSee('name="name"', false)
            ->assertDontSee('type="password"', false);
        $this->post('/admin/google', [
            'id_token' => 'google-id-token',
            'name' => 'Entered Admin Name',
        ])->assertRedirect('/admin');

        $this->get('/admin')
            ->assertOk()
            ->assertSee('id="admin-app"', false)
            ->assertSee('Entered Admin Name');
        $this->assertSame('Entered Admin Name', $admin->fresh()->name);
    }

    public function test_non_admin_google_account_cannot_access_the_admin_dashboard(): void
    {
        User::factory()->create([
            'email' => 'student@snsu.edu.ph',
            'is_admin' => false,
        ]);
        config(['services.google.client_id' => 'matchlearn-web-client']);
        Http::fake([
            'https://oauth2.googleapis.com/tokeninfo*' => Http::response([
                'aud' => 'matchlearn-web-client',
                'iss' => 'https://accounts.google.com',
                'exp' => (string) now()->addMinutes(5)->timestamp,
                'email' => 'student@snsu.edu.ph',
                'email_verified' => true,
                'sub' => 'google-student-123',
            ]),
        ]);

        $this->from('/admin/login')
            ->post('/admin/google', [
                'id_token' => 'google-id-token',
                'name' => 'Student Name',
            ])
            ->assertRedirect('/admin/login')
            ->assertSessionHasErrors('google');
    }

    public function test_non_admin_users_cannot_open_the_admin_dashboard_or_api(): void
    {
        $student = User::factory()->create();

        $this->actingAs($student)
            ->get('/admin')
            ->assertForbidden();

        $this->actingAs($student)
            ->getJson('/admin/api/analytics')
            ->assertForbidden();
    }

    public function test_guests_are_redirected_to_the_admin_login_page(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_admin_can_view_metrics_and_manage_students_and_reports(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $student = User::factory()->create();
        $photoUrl = 'https://example.test/storage/profile-photos/student.jpg';
        Profile::create([
            'user_id' => $student->id,
            'institution' => 'SNSU',
            'profile_photo_url' => $photoUrl,
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/api/analytics')
            ->assertOk()
            ->assertJsonPath('users', 2)
            ->assertJsonPath('active_users', 2);

        $this->actingAs($admin)
            ->getJson('/admin/api/users?status=active&search='.$student->email)
            ->assertOk()
            ->assertJsonFragment(['id' => $student->id])
            ->assertJsonPath('data.0.profile_photo_url', $photoUrl);

        $this->actingAs($admin)
            ->patchJson("/admin/api/users/{$student->id}/status", ['is_suspended' => true])
            ->assertOk()
            ->assertJsonPath('user.is_suspended', true);

        $this->assertDatabaseHas('users', ['id' => $student->id, 'is_suspended' => true]);
    }

    public function test_admin_can_list_and_filter_study_groups_and_sessions(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $creator = User::factory()->create(['name' => 'Group Creator']);
        $group = StudyGroup::create([
            'created_by' => $creator->id,
            'name' => 'Exam preparation',
            'course' => 'IT 213',
        ]);
        $group->members()->attach($creator->id, ['role' => 'admin']);
        $session = StudySession::create([
            'host_user_id' => $creator->id,
            'participant_user_id' => null,
            'study_group_id' => $group->id,
            'topic' => 'Database final review',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
            'location' => 'Library',
            'status' => 'confirmed',
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/api/groups?search=IT%20213')
            ->assertOk()
            ->assertJsonPath('data.0.id', $group->id)
            ->assertJsonPath('data.0.creator', 'Group Creator')
            ->assertJsonPath('data.0.members_count', 1);

        $this->actingAs($admin)
            ->getJson('/admin/api/study-sessions?status=confirmed&search=Database')
            ->assertOk()
            ->assertJsonPath('data.0.id', $session->id)
            ->assertJsonPath('data.0.host', 'Group Creator')
            ->assertJsonPath('data.0.group', 'Exam preparation')
            ->assertJsonPath('data.0.status', 'confirmed');
    }

    public function test_admin_can_edit_and_delete_study_groups_and_sessions(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $creator = User::factory()->create();
        $group = StudyGroup::create([
            'created_by' => $creator->id,
            'name' => 'Original group',
            'course' => 'IT 213',
        ]);
        $session = StudySession::create([
            'host_user_id' => $creator->id,
            'participant_user_id' => null,
            'study_group_id' => $group->id,
            'topic' => 'Original session',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
            'location' => 'Library',
            'status' => 'pending',
        ]);
        $linkedSession = StudySession::create([
            'host_user_id' => $creator->id,
            'participant_user_id' => null,
            'study_group_id' => $group->id,
            'topic' => 'Session removed with its group',
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(3)->addHour(),
            'location' => 'Library',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->putJson("/admin/api/groups/{$group->id}", [
                'name' => 'Updated group',
                'course' => 'IT 214',
                'schedule' => 'Mondays',
                'description' => 'Weekly review group',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Study group updated.');

        $this->assertDatabaseHas('study_groups', [
            'id' => $group->id,
            'name' => 'Updated group',
            'course' => 'IT 214',
            'schedule' => 'Mondays',
        ]);

        $startsAt = now()->addDays(2)->startOfHour();
        $endsAt = $startsAt->copy()->addHour();
        $this->actingAs($admin)
            ->putJson("/admin/api/study-sessions/{$session->id}", [
                'topic' => 'Updated session',
                'starts_at' => $startsAt->toDateTimeString(),
                'ends_at' => $endsAt->toDateTimeString(),
                'location' => 'Study room',
                'status' => 'confirmed',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Study session updated.');

        $this->assertDatabaseHas('study_sessions', [
            'id' => $session->id,
            'topic' => 'Updated session',
            'location' => 'Study room',
            'status' => 'confirmed',
        ]);

        $this->actingAs($admin)
            ->deleteJson("/admin/api/study-sessions/{$session->id}")
            ->assertOk();
        $this->assertDatabaseMissing('study_sessions', ['id' => $session->id]);

        $this->actingAs($admin)
            ->deleteJson("/admin/api/groups/{$group->id}")
            ->assertOk();
        $this->assertDatabaseMissing('study_groups', ['id' => $group->id]);
        $this->assertDatabaseMissing('study_sessions', ['id' => $linkedSession->id]);
    }

    public function test_admin_group_and_session_edits_validate_input(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $creator = User::factory()->create();
        $group = StudyGroup::create([
            'created_by' => $creator->id,
            'name' => 'Study group',
            'course' => 'IT 213',
        ]);
        $session = StudySession::create([
            'host_user_id' => $creator->id,
            'participant_user_id' => null,
            'study_group_id' => $group->id,
            'topic' => 'Study session',
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDay()->addHour(),
            'location' => 'Library',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->putJson("/admin/api/groups/{$group->id}", ['name' => ''])
            ->assertUnprocessable();

        $this->actingAs($admin)
            ->putJson("/admin/api/study-sessions/{$session->id}", [
                'topic' => 'Session',
                'starts_at' => now()->addDay()->toDateTimeString(),
                'ends_at' => now()->toDateTimeString(),
                'location' => 'Library',
                'status' => 'invalid',
            ])
            ->assertUnprocessable();
    }

    public function test_admin_can_review_and_dismiss_safety_reports(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $reporter = User::factory()->create();
        $reportedUser = User::factory()->create();
        $report = UserReport::create([
            'reporter_user_id' => $reporter->id,
            'reported_user_id' => $reportedUser->id,
            'reason' => 'harassment',
            'details' => 'Repeated unwanted messages.',
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/api/reports?status=pending')
            ->assertOk()
            ->assertJsonFragment(['id' => $report->id, 'details' => 'Repeated unwanted messages.']);

        $this->actingAs($admin)
            ->patchJson("/admin/api/reports/{$report->id}", ['status' => 'reviewed'])
            ->assertOk()
            ->assertJsonPath('status', 'reviewed');

        $this->assertDatabaseHas('user_reports', ['id' => $report->id, 'status' => 'reviewed']);
    }

    public function test_admin_can_update_matching_weights_only_when_the_total_is_one_hundred(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $weights = MatchingSetting::DEFAULT_WEIGHTS;
        $weights['courses'] = 30;
        $weights['program'] = 15;

        $this->actingAs($admin)
            ->putJson('/admin/api/matching-weights', ['weights' => $weights])
            ->assertOk()
            ->assertJsonPath('weights.courses', 30)
            ->assertJsonPath('weights.program', 15);

        $this->assertDatabaseHas('matching_settings', [
            'key' => 'default',
            'weights->courses' => 30,
        ]);

        $weights['courses'] = 31;
        $this->actingAs($admin)
            ->putJson('/admin/api/matching-weights', ['weights' => $weights])
            ->assertUnprocessable();
    }
}
