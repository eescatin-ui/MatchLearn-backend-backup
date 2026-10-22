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
            'College of Computing and Information Sciences',
            'College of Engineering',
            'College of Arts and Sciences',
            'College of Teacher Education',
            'College of Technology',
        ] as $name) {
            $this->assertTrue($departments->contains('name', $name), "Missing SNSU Main Campus department: {$name}");
        }

        $artsAndSciences = $departments->firstWhere('name', 'College of Arts and Sciences');
        $artsPrograms = collect($artsAndSciences['programs'])->pluck('name');
        $this->assertTrue($artsPrograms->contains('Bachelor of Science in Mathematics'));
        $this->assertTrue($artsPrograms->contains('Bachelor of Science in Environmental Science'));
        $this->assertTrue($artsPrograms->contains('Bachelor of Arts in English Language'));

        $technology = $departments->firstWhere('name', 'College of Technology');
        $technologyPrograms = collect($technology['programs'])->pluck('name');
        $this->assertTrue($technologyPrograms->contains('Bachelor of Engineering Technology'));
        $this->assertTrue($technologyPrograms->contains('Bachelor of Science in Hospitality Management'));
    }
}
