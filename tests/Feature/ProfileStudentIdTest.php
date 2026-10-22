<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileStudentIdTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_store_student_id_in_profile(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::factory()->create([
            'email' => 'student@snsu.edu.ph',
            'password' => bcrypt('Password123'),
        ]);

        $this->actingAs($user, 'sanctum');

        $response = $this->postJson('/api/v1/profiles', [
            'name' => 'Updated Student',
            'institution' => 'SNSU',
            'department_id' => 1,
            'program_id' => 1,
            'year_level' => 3,
            'learning_style_id' => 1,
            'student_id' => '2024-0001',
            'bio' => 'I like collaborative learning.',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.profile.student_id', '2024-0001');
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Updated Student',
        ]);

        $this->assertDatabaseHas('profiles', [
            'user_id' => $user->id,
            'student_id' => '2024-0001',
        ]);
    }

    public function test_user_can_update_profile_without_rejecting_their_own_student_id(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::factory()->create([
            'email' => 'student@snsu.edu.ph',
            'password' => bcrypt('Password123'),
        ]);
        $this->actingAs($user, 'sanctum');

        $this->postJson('/api/v1/profiles', [
            'institution' => 'SNSU',
            'student_id' => '2024-0001',
            'bio' => 'First bio.',
        ])->assertCreated();

        $this->putJson('/api/v1/profiles', [
            'institution' => 'SNSU',
            'student_id' => '2024-0001',
            'bio' => 'Updated bio.',
        ])->assertOk()
            ->assertJsonPath('data.profile.student_id', '2024-0001')
            ->assertJsonPath('data.profile.bio', 'Updated bio.');
    }

    public function test_user_can_save_and_update_private_contact_details(): void
    {
        $this->seed(DatabaseSeeder::class);
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        $this->postJson('/api/v1/profiles', [
            'institution' => 'SNSU',
            'phone_number' => '+63 912 345 6789',
            'address' => '123 Main Street, Surigao City',
            'phone_number_public' => false,
            'address_public' => false,
        ])->assertCreated()
            ->assertJsonPath('data.profile.phone_number', '+63 912 345 6789')
            ->assertJsonPath('data.profile.address', '123 Main Street, Surigao City')
            ->assertJsonPath('data.profile.phone_number_public', false)
            ->assertJsonPath('data.profile.address_public', false);

        $this->putJson('/api/v1/profiles', [
            'institution' => 'SNSU',
            'phone_number' => '09123456789',
            'address' => '456 Updated Street, Surigao City',
            'phone_number_public' => true,
            'address_public' => true,
        ])->assertOk()
            ->assertJsonPath('data.profile.phone_number', '09123456789')
            ->assertJsonPath('data.profile.address', '456 Updated Street, Surigao City')
            ->assertJsonPath('data.profile.phone_number_public', true)
            ->assertJsonPath('data.profile.address_public', true);

        $this->assertDatabaseHas('profiles', [
            'user_id' => $user->id,
            'phone_number' => '09123456789',
            'address' => '456 Updated Street, Surigao City',
            'phone_number_public' => true,
            'address_public' => true,
        ]);
    }

    public function test_user_can_save_and_update_gender_and_marital_status(): void
    {
        $this->seed(DatabaseSeeder::class);
        $user = User::factory()->create();
        $this->actingAs($user, 'sanctum');

        $this->postJson('/api/v1/profiles', [
            'institution' => 'SNSU',
            'gender' => 'Non-binary',
            'marital_status' => 'Single',
        ])->assertCreated()
            ->assertJsonPath('data.profile.gender', 'Non-binary')
            ->assertJsonPath('data.profile.marital_status', 'Single');

        $this->putJson('/api/v1/profiles', [
            'institution' => 'SNSU',
            'gender' => 'Prefer not to say',
            'marital_status' => 'Married',
        ])->assertOk()
            ->assertJsonPath('data.profile.gender', 'Prefer not to say')
            ->assertJsonPath('data.profile.marital_status', 'Married');

        $this->assertDatabaseHas('profiles', [
            'user_id' => $user->id,
            'gender' => 'Prefer not to say',
            'marital_status' => 'Married',
        ]);
    }

    public function test_profile_rejects_invalid_phone_number_format(): void
    {
        $this->seed(DatabaseSeeder::class);
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/profiles', [
                'institution' => 'SNSU',
                'phone_number' => 'Call me at 0912',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone_number']);
    }

    public function test_profile_rejects_invalid_gender_and_marital_status(): void
    {
        $this->seed(DatabaseSeeder::class);
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/profiles', [
                'institution' => 'SNSU',
                'gender' => 'invalid',
                'marital_status' => 'invalid',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['gender', 'marital_status']);
    }
}
