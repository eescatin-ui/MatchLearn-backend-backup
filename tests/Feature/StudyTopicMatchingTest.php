<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Course;
use App\Models\LearningStyle;
use App\Models\MatchingSetting;
use App\Models\Profile;
use App\Models\Program;
use App\Models\User;
use App\Services\MatchingService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudyTopicMatchingTest extends TestCase
{
    use RefreshDatabase;

    public function test_students_match_by_normalized_course_code_and_complementary_needs(): void
    {
        $this->seed(DatabaseSeeder::class);
        $department = Department::where('name', 'College of Computing and Information Sciences')->firstOrFail();
        $program = Program::where('department_id', $department->id)->firstOrFail();
        $style = LearningStyle::where('name', 'Visual')->firstOrFail();

        $student = $this->createStudent($department, $program, $style, [
            'study_courses' => [
                ['course_code' => 'IT 213', 'course_code_normalized' => 'IT213', 'course_name' => 'Database Management Systems'],
            ],
            'academic_strengths' => ['Programming'],
            'academic_needs' => ['Database'],
            'study_mode' => 'online',
        ]);
        $buddy = $this->createStudent($department, $program, $style, [
            'study_courses' => [
                ['course_code' => 'it-213', 'course_code_normalized' => 'IT213', 'course_name' => 'Databases'],
            ],
            'academic_strengths' => ['Database'],
            'academic_needs' => ['Programming'],
            'study_mode' => 'either',
        ]);

        $matches = app(MatchingService::class)->getMatchesForUser($student);
        $match = $matches->first();

        $this->assertNotNull($match);
        $this->assertSame($buddy->id, $match->matched_user_id);
        $this->assertSame(1, $match->shared_course_count);
        $this->assertStringContainsString('IT 213 - Database Management Systems', $match->reasons[0]);
        $this->assertContains('Your strengths can help with each other\'s academic needs.', $match->reasons);
        $this->assertGreaterThan(0, $match->compatibility_score);

        $buddy->profile->studyCourses()->update([
            'course_code' => 'CS 213',
            'course_code_normalized' => 'CS213',
        ]);
        $withoutSameCode = app(MatchingService::class)->getMatchesForUser($student)->first();
        $this->assertNotNull($withoutSameCode);
        $this->assertSame(0, $withoutSameCode->shared_course_count);
        $this->assertNotContains('You are studying the same course code: IT 213 - Database Management Systems.', $withoutSameCode->reasons);
    }

    public function test_admin_matching_weights_are_applied_when_recommendations_refresh(): void
    {
        $this->seed(DatabaseSeeder::class);
        $department = Department::where('name', 'College of Computing and Information Sciences')->firstOrFail();
        $program = Program::where('department_id', $department->id)->firstOrFail();
        $style = LearningStyle::where('name', 'Visual')->firstOrFail();
        $preferences = [
            'study_courses' => [
                ['course_code' => 'IT 213', 'course_code_normalized' => 'IT213', 'course_name' => 'Database Management Systems'],
            ],
            'study_mode' => 'online',
        ];
        $student = $this->createStudent($department, $program, $style, $preferences);
        $this->createStudent($department, $program, $style, $preferences);

        MatchingSetting::query()->updateOrCreate(
            ['key' => 'default'],
            ['weights' => [
                'courses' => 100,
                'academic_complementarity' => 0,
                'program' => 0,
                'learning_style' => 0,
                'schedule' => 0,
                'year_level' => 0,
                'study_mode' => 0,
            ]]
        );

        $match = app(MatchingService::class)->getMatchesForUser($student)->first();

        $this->assertNotNull($match);
        $this->assertSame(100, $match->compatibility_score);
    }

    public function test_matches_detect_shared_catalog_courses_and_explain_them(): void
    {
        $this->seed(DatabaseSeeder::class);
        $department = Department::where('name', 'College of Computing and Information Sciences')->firstOrFail();
        $program = Program::where('department_id', $department->id)->firstOrFail();
        $style = LearningStyle::where('name', 'Visual')->firstOrFail();
        $student = $this->createStudent($department, $program, $style, [
            'study_courses' => [
                ['course_code' => 'CS 101', 'course_code_normalized' => 'CS101', 'course_name' => 'Programming'],
            ],
        ]);
        $buddy = $this->createStudent($department, $program, $style, [
            'study_courses' => [
                ['course_code' => 'BIO 110', 'course_code_normalized' => 'BIO110', 'course_name' => 'Biology'],
            ],
        ]);
        $catalogCourse = Course::create([
            'code' => 'IT 213',
            'name' => 'Database Management Systems',
            'department_id' => $department->id,
        ]);
        $student->courses()->attach($catalogCourse);
        $buddy->courses()->attach($catalogCourse);

        $match = app(MatchingService::class)
            ->getMatchesForUser($student)
            ->firstWhere('matched_user_id', $buddy->id);

        $this->assertNotNull($match);
        $this->assertSame(1, $match->shared_course_count);
        $this->assertContains(
            'You are studying the same course code: IT 213 - Database Management Systems.',
            $match->reasons
        );

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/v1/matches')
            ->assertOk()
            ->assertJsonPath('data.matches.0.shared_course_count', 1)
            ->assertJsonFragment([
                'You are studying the same course code: IT 213 - Database Management Systems.',
            ]);
    }

    public function test_ge_103_matches_by_code_not_ethics_course_description(): void
    {
        $this->seed(DatabaseSeeder::class);
        $department = Department::where('name', 'College of Computing and Information Sciences')->firstOrFail();
        $program = Program::where('department_id', $department->id)->firstOrFail();
        $style = LearningStyle::where('name', 'Visual')->firstOrFail();
        $student = $this->createStudent($department, $program, $style, [
            'study_courses' => [
                ['course_code' => 'GE 103 - Ethics', 'course_code_normalized' => 'GE103ETHICS', 'course_name' => 'Ethics'],
            ],
        ]);
        $buddy = $this->createStudent($department, $program, $style, [
            'study_courses' => [
                ['course_code' => 'GE 103 - Moral Philosophy', 'course_code_normalized' => 'GE103MORALPHILOSOPHY', 'course_name' => 'Moral Philosophy'],
            ],
        ]);

        $match = app(MatchingService::class)
            ->getMatchesForUser($student)
            ->firstWhere('matched_user_id', $buddy->id);

        $this->assertNotNull($match);
        $this->assertSame(1, $match->shared_course_count);
        $this->assertContains(
            'You are studying the same course code: GE 103 - Ethics.',
            $match->reasons
        );

        $buddy->profile->studyCourses()->update([
            'course_code' => 'GE 104 - Ethics',
            'course_code_normalized' => 'GE104ETHICS',
        ]);
        $withoutSameCode = app(MatchingService::class)
            ->getMatchesForUser($student)
            ->firstWhere('matched_user_id', $buddy->id);

        $this->assertNotNull($withoutSameCode);
        $this->assertSame(0, $withoutSameCode->shared_course_count);
        $this->assertNotContains(
            'You are studying the same course code: GE 103 - Ethics.',
            $withoutSameCode->reasons
        );
    }

    private function createStudent(Department $department, Program $program, LearningStyle $style, array $preferences): User
    {
        $user = User::factory()->create();
        $profile = Profile::create([
            'user_id' => $user->id,
            'institution' => 'SNSU',
            'department_id' => $department->id,
            'program_id' => $program->id,
            'year_level' => 2,
            'learning_style_id' => $style->id,
            ...array_diff_key($preferences, ['study_courses' => true]),
        ]);
        foreach ($preferences['study_courses'] ?? [] as $course) {
            $profile->studyCourses()->create($course);
        }

        return $user;
    }
}
