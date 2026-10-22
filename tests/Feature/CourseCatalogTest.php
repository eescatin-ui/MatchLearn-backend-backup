<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_load_courses_with_their_department(): void
    {
        $this->seed(DatabaseSeeder::class);
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/courses')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.courses.0.department.id', fn ($id) => is_int($id))
            ->assertJsonPath('data.courses.0.department.name', fn ($name) => is_string($name));
    }

    public function test_catalog_includes_snsu_main_campus_colleges_and_current_programs(): void
    {
        $this->seed(DatabaseSeeder::class);
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/catalog')
            ->assertOk();

        $departments = collect($response->json('data.departments'));

        foreach ([
            'College of Engineering and Architecture',
            'College of Information and Computing Sciences',
            'College of Industrial Technology',
            'College of Teacher Education',
            'College of Business and Hospitality Management',
        ] as $name) {
            $this->assertTrue($departments->contains('name', $name), "Missing SNSU Main Campus department: {$name}");
        }

        $expectedPrograms = [
            'College of Engineering and Architecture' => [
                'Bachelor of Science in Civil Engineering (BSCE)',
                'Bachelor of Science in Electrical Engineering (BSEE)',
                'Bachelor of Science in Computer Engineering (BSCpE)',
                'Bachelor of Science in Electronics Engineering (BSEC / ECE)',
            ],
            'College of Information and Computing Sciences' => [
                'Bachelor of Science in Information Technology (BSIT)',
                'Bachelor of Science in Computer Science (BSCS)',
                'Bachelor of Science in Information Systems (BSIS)',
            ],
            'College of Industrial Technology' => [
                'Bachelor of Industrial Technology (BIT) - Automotive',
                'Bachelor of Industrial Technology (BIT) - Electrical',
                'Bachelor of Industrial Technology (BIT) - Electronics',
                'Bachelor of Industrial Technology (BIT) - Mechanical',
                'Bachelor of Industrial Technology (BIT) - Drafting',
                'Bachelor of Industrial Technology (BIT) - Civil',
                'Bachelor of Industrial Technology (BIT) - Foods',
                'Bachelor of Industrial Technology (BIT) - Welding and Fabrication',
                'Bachelor of Industrial Technology (BIT) - Refrigeration and Air-Conditioning',
                'Associate in Industrial Technology (AIT)',
            ],
            'College of Teacher Education' => [
                'Bachelor of Elementary Education (BEEd)',
                'Bachelor of Secondary Education (BSEd) - English',
                'Bachelor of Secondary Education (BSEd) - Mathematics',
                'Bachelor of Secondary Education (BSEd) - Science/Biological/Physical Science',
                'Bachelor of Secondary Education (BSEd) - Filipino',
                'Bachelor of Technical-Vocational Teacher Education (BTVTED)',
            ],
            'College of Business and Hospitality Management' => [
                'Bachelor of Science in Hospitality Management (BSHM) / Hotel and Restaurant Management',
            ],
        ];

        foreach ($expectedPrograms as $departmentName => $programNames) {
            $department = $departments->firstWhere('name', $departmentName);
            $actualProgramNames = collect($department['programs'])->pluck('name')->all();
            $this->assertEqualsCanonicalizing($programNames, $actualProgramNames, "Unexpected programs for {$departmentName}");
        }
    }
}
