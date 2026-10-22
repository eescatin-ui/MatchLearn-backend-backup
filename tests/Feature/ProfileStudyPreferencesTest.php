<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileStudyPreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_save_structured_courses_strengths_needs_and_mode(): void
    {
        $this->seed(DatabaseSeeder::class);
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/profiles', [
                'institution' => 'SNSU',
                'study_courses' => [
                    ['course_code' => 'IT 213', 'course_name' => 'Database Management Systems'],
                ],
                'academic_strengths' => [' Programming ', 'Mathematics'],
                'academic_needs' => ['Database'],
                'study_mode' => 'online',
            ])
            ->assertCreated()
            ->assertJsonPath('data.profile.study_courses.0.course_code', 'IT 213')
            ->assertJsonPath('data.profile.study_courses.0.normalized_course_code', 'IT213')
            ->assertJsonPath('data.profile.study_courses.0.course_name', 'Database Management Systems')
            ->assertJsonPath('data.profile.academic_strengths', ['Programming', 'Mathematics'])
            ->assertJsonPath('data.profile.academic_needs', ['Database'])
            ->assertJsonPath('data.profile.study_mode', 'online');

        $this->assertDatabaseHas('profiles', [
            'user_id' => $user->id,
            'study_mode' => 'online',
        ]);
        $this->assertDatabaseHas('profile_study_courses', [
            'course_code' => 'IT 213',
            'course_code_normalized' => 'IT213',
            'course_name' => 'Database Management Systems',
        ]);
    }

    public function test_user_can_reuse_and_remove_saved_courses_when_updating_profile(): void
    {
        $this->seed(DatabaseSeeder::class);
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/profiles', [
                'institution' => 'SNSU',
                'study_courses' => [
                    ['course_code' => 'IT 213', 'course_name' => 'Database Management Systems'],
                    ['course_code' => 'IT 214', 'course_name' => 'Data Structures'],
                ],
            ])
            ->assertCreated();

        $this->putJson('/api/v1/profiles', [
            'study_courses' => [
                ['course_code' => 'IT-213', 'course_name' => 'Database Management Systems'],
            ],
        ])->assertOk()
            ->assertJsonCount(1, 'data.profile.study_courses')
            ->assertJsonPath('data.profile.study_courses.0.normalized_course_code', 'IT213');

        $this->assertDatabaseCount('profile_study_courses', 1);
    }

    public function test_user_can_save_and_reload_multiple_study_courses(): void
    {
        $this->seed(DatabaseSeeder::class);
        $user = User::factory()->create();
        $courses = [
            ['course_code' => 'GE 103', 'course_name' => 'Ethics'],
            ['course_code' => 'IT 213', 'course_name' => 'Database Management Systems'],
            ['course_code' => 'CS 101', 'course_name' => 'Introduction to Programming'],
        ];

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/profiles', [
                'institution' => 'SNSU',
                'study_courses' => $courses,
            ])
            ->assertCreated()
            ->assertJsonCount(3, 'data.profile.study_courses');
        foreach ($courses as $course) {
            $this->assertDatabaseHas('profile_study_courses', $course);
        }

        $this->assertDatabaseCount('profile_study_courses', 3);

        $this->actingAs($user->fresh(), 'sanctum')
            ->getJson('/api/v1/profiles')
            ->assertOk()
            ->assertJsonCount(3, 'data.profile.study_courses')
            ->assertJsonPath('data.profile.study_courses.0.course_code', 'GE 103')
            ->assertJsonPath('data.profile.study_courses.1.course_code', 'IT 213')
            ->assertJsonPath('data.profile.study_courses.2.course_code', 'CS 101');

        $updatedCourses = [
            $courses[0],
            $courses[1],
            ['course_code' => 'MATH 110', 'course_name' => 'College Algebra'],
        ];

        $this->putJson('/api/v1/profiles', ['study_courses' => $updatedCourses])
            ->assertOk()
            ->assertJsonCount(3, 'data.profile.study_courses');

        $this->assertDatabaseCount('profile_study_courses', 3);
        $this->assertDatabaseHas('profile_study_courses', [
            'profile_id' => $user->profile()->value('id'),
            'course_code' => 'MATH 110',
            'course_name' => 'College Algebra',
        ]);
    }

    public function test_user_cannot_save_duplicate_normalized_course_codes(): void
    {
        $this->seed(DatabaseSeeder::class);
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/profiles', [
                'institution' => 'SNSU',
                'study_courses' => [
                    ['course_code' => 'IT 213', 'course_name' => 'Database Management Systems'],
                    ['course_code' => 'it-213', 'course_name' => 'Database Systems'],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['study_courses.1.course_code']);
    }
}
