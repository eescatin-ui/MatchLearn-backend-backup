<?php

namespace Tests\Feature;

use App\Models\BuddyRequest;
use App\Models\LearningStyle;
use App\Models\Profile;
use App\Models\StudyGroup;
use App\Models\StudyGroupReview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuddyAndGroupDetailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_group_avatar_options_are_validated_stored_and_returned(): void
    {
        $owner = User::factory()->create();
        $payload = [
            'name' => 'Science circle',
            'course' => 'BIO 101',
            'avatar_icon' => 'flask-outline',
            'avatar_color' => '#15803d',
            'study_style' => 'Visual',
            'preferred_study_time' => 'Morning',
            'study_mode' => 'online',
        ];

        $groupId = $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/groups', $payload)
            ->assertCreated()
            ->assertJsonPath('data.group.avatar_icon', 'flask-outline')
            ->assertJsonPath('data.group.avatar_color', '#15803d')
            ->assertJsonPath('data.group.study_style', 'Visual')
            ->assertJsonPath('data.group.preferred_study_time', 'Morning')
            ->assertJsonPath('data.group.study_mode', 'online')
            ->json('data.group.id');

        $this->assertDatabaseHas('study_groups', [
            'id' => $groupId,
            'avatar_icon' => 'flask-outline',
            'avatar_color' => '#15803d',
        ]);

        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/groups')
            ->assertOk()
            ->assertJsonPath('data.groups.0.avatar_icon', 'flask-outline')
            ->assertJsonPath('data.groups.0.avatar_color', '#15803d');

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/groups/{$groupId}")
            ->assertOk()
            ->assertJsonPath('data.group.avatar_icon', 'flask-outline')
            ->assertJsonPath('data.group.avatar_color', '#15803d')
            ->assertJsonPath('data.group.members_list.0.average_rating', null)
            ->assertJsonPath('data.group.members_list.0.ratings_count', 0);

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson('/api/v1/groups', [
                'name' => 'Invalid icon',
                'course' => 'BIO 101',
                'avatar_icon' => 'not-an-icon',
                'avatar_color' => '#15803d',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['avatar_icon']);
    }

    public function test_snsu_department_group_icons_are_accepted(): void
    {
        $owner = User::factory()->create();

        foreach ([
            'construct-outline',
            'laptop-outline',
            'build-outline',
            'school-outline',
            'business-outline',
        ] as $icon) {
            $this->actingAs($owner, 'sanctum')
                ->postJson('/api/v1/groups', [
                    'name' => "Study group {$icon}",
                    'course' => 'SNSU',
                    'avatar_icon' => $icon,
                ])
                ->assertCreated()
                ->assertJsonPath('data.group.avatar_icon', $icon);
        }
    }

    public function test_only_group_admin_can_edit_group_details_and_avatar(): void
    {
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $groupId = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/groups', ['name' => 'Study team', 'course' => 'IT 213'])
            ->assertCreated()
            ->json('data.group.id');
        $group = StudyGroup::findOrFail($groupId);
        $group->members()->attach($member->id, ['role' => 'member']);

        $this->actingAs($member, 'sanctum')
            ->putJson("/api/v1/groups/{$groupId}", ['name' => 'Unauthorized update'])
            ->assertForbidden();

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/groups/{$groupId}", [
                'name' => 'Updated study team',
                'course' => 'IT 214',
                'schedule' => 'Mondays',
                'description' => 'Weekly study sessions',
                'avatar_icon' => 'calculator-outline',
                'avatar_color' => '#7c3aed',
                'study_style' => 'Kinesthetic',
                'preferred_study_time' => 'Evening',
                'study_mode' => 'in_person',
            ])
            ->assertOk()
            ->assertJsonPath('data.group.name', 'Updated study team')
            ->assertJsonPath('data.group.avatar_icon', 'calculator-outline')
            ->assertJsonPath('data.group.avatar_color', '#7c3aed')
            ->assertJsonPath('data.group.study_style', 'Kinesthetic')
            ->assertJsonPath('data.group.preferred_study_time', 'Evening')
            ->assertJsonPath('data.group.study_mode', 'in_person');

        $this->assertDatabaseHas('study_groups', [
            'id' => $groupId,
            'name' => 'Updated study team',
            'course' => 'IT 214',
            'schedule' => 'Mondays',
            'description' => 'Weekly study sessions',
            'avatar_icon' => 'calculator-outline',
            'avatar_color' => '#7c3aed',
            'study_style' => 'Kinesthetic',
            'preferred_study_time' => 'Evening',
            'study_mode' => 'in_person',
        ]);
    }

    public function test_discover_groups_include_compatibility_and_rating(): void
    {
        $style = LearningStyle::create(['name' => 'Visual']);
        $viewer = User::factory()->create();
        Profile::create([
            'user_id' => $viewer->id,
            'institution' => 'SNSU',
            'learning_style_id' => $style->id,
            'study_mode' => 'online',
        ]);
        $viewer->availabilities()->create([
            'day_of_week' => 'monday',
            'start_time' => '08:00',
            'end_time' => '12:00',
        ]);

        $owner = User::factory()->create();
        $group = StudyGroup::create([
            'created_by' => $owner->id,
            'name' => 'Morning Visual Study',
            'course' => 'IT 213',
            'study_style' => 'Visual',
            'preferred_study_time' => 'Morning',
            'study_mode' => 'online',
        ]);
        StudyGroupReview::create([
            'study_group_id' => $group->id,
            'reviewer_user_id' => $owner->id,
            'rating' => 4,
        ]);
        StudyGroupReview::create([
            'study_group_id' => $group->id,
            'reviewer_user_id' => $viewer->id,
            'rating' => 5,
        ]);

        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/v1/groups/discover')
            ->assertOk()
            ->assertJsonPath('data.groups.0.compatibility_score', 100)
            ->assertJsonPath('data.groups.0.average_rating', 4.5)
            ->assertJsonPath('data.groups.0.ratings_count', 2);
    }

    public function test_only_accepted_buddies_can_view_profile_and_remove_connection(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $outsider = User::factory()->create();
        BuddyRequest::create([
            'requester_id' => $first->id,
            'recipient_id' => $second->id,
            'status' => 'accepted',
        ]);

        $this->actingAs($first, 'sanctum')
            ->getJson("/api/v1/buddies/{$second->id}")
            ->assertOk()
            ->assertJsonPath('data.buddy.id', $second->id)
            ->assertJsonPath('data.buddy.name', $second->name);

        $this->actingAs($outsider, 'sanctum')
            ->getJson("/api/v1/buddies/{$second->id}")
            ->assertNotFound();

        $this->actingAs($first, 'sanctum')
            ->deleteJson("/api/v1/buddies/{$second->id}")
            ->assertOk();

        $this->assertDatabaseMissing('buddy_requests', [
            'requester_id' => $first->id,
            'recipient_id' => $second->id,
            'status' => 'accepted',
        ]);
    }

    public function test_group_members_can_rate_and_update_a_group_rating(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $outsider = User::factory()->create();
        $memberPhoto = 'https://matchlearn.test/storage/profile-photos/group-member.png';
        Profile::create(['user_id' => $member->id, 'institution' => 'SNSU', 'profile_photo_url' => $memberPhoto]);
        $groupId = $this->actingAs($owner, 'sanctum')
            ->postJson('/api/v1/groups', ['name' => 'Study team', 'course' => 'IT 213'])
            ->assertCreated()
            ->json('data.group.id');

        $requestId = $this->actingAs($member, 'sanctum')
            ->postJson("/api/v1/groups/{$groupId}/join")
            ->assertCreated()
            ->json('data.request_id');
        $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/groups')
            ->assertOk()
            ->assertJsonPath('data.groups.0.join_requests.0.profile_photo_url', $memberPhoto);
        $this->actingAs($owner, 'sanctum')
            ->patchJson("/api/v1/groups/{$groupId}/join-requests/{$requestId}", ['action' => 'accept'])
            ->assertOk();

        $this->actingAs($member, 'sanctum')
            ->postJson("/api/v1/groups/{$groupId}/reviews", ['rating' => 5, 'comment' => 'Helpful group'])
            ->assertOk();
        $this->actingAs($member, 'sanctum')
            ->postJson("/api/v1/groups/{$groupId}/reviews", ['rating' => 4, 'comment' => 'Updated review'])
            ->assertOk();

        $this->assertDatabaseCount('study_group_reviews', 1);
        $this->assertDatabaseHas('study_group_reviews', [
            'study_group_id' => $groupId,
            'reviewer_user_id' => $member->id,
            'rating' => 4,
            'comment' => 'Updated review',
        ]);

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/groups/{$groupId}")
            ->assertOk()
            ->assertJsonPath('data.group.average_rating', 4)
            ->assertJsonPath('data.group.reviews.0.reviewer_name', $member->name)
            ->assertJsonPath('data.group.members_list.0.id', $owner->id)
            ->assertJsonPath('data.group.members_list.1.id', $member->id)
            ->assertJsonPath('data.group.members_list.1.profile_photo_url', $memberPhoto);

        $this->actingAs($outsider, 'sanctum')
            ->postJson("/api/v1/groups/{$groupId}/reviews", ['rating' => 5])
            ->assertNotFound();
    }

    public function test_admin_must_transfer_admin_role_before_leaving_group(): void
    {
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $groupId = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/groups', ['name' => 'Handoff group', 'course' => 'IT 214'])
            ->assertCreated()
            ->json('data.group.id');

        $requestId = $this->actingAs($member, 'sanctum')
            ->postJson("/api/v1/groups/{$groupId}/join")
            ->assertCreated()
            ->json('data.request_id');
        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/groups/{$groupId}/join-requests/{$requestId}", ['action' => 'accept'])
            ->assertOk();

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/groups/{$groupId}/leave")
            ->assertUnprocessable();

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/groups/{$groupId}/leave", ['new_admin_user_id' => $member->id])
            ->assertOk();

        $this->assertDatabaseHas('study_groups', ['id' => $groupId, 'created_by' => $member->id]);
        $this->assertDatabaseHas('study_group_members', [
            'study_group_id' => $groupId,
            'user_id' => $member->id,
            'role' => 'admin',
        ]);
        $this->assertDatabaseMissing('study_group_members', [
            'study_group_id' => $groupId,
            'user_id' => $admin->id,
        ]);
    }

    public function test_only_admin_can_delete_their_single_member_group(): void
    {
        $admin = User::factory()->create();
        $groupId = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/groups', ['name' => 'Solo group', 'course' => 'IT 215'])
            ->assertCreated()
            ->json('data.group.id');

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/groups/{$groupId}")
            ->assertOk();

        $this->assertDatabaseMissing('study_groups', ['id' => $groupId]);
    }
}
