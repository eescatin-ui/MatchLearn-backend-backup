<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_only_registration_is_rejected_without_google_verification(): void
    {
        foreach (['student@ssct.edu.ph', 'student@snsu.edu.ph', 'student@gmail.com'] as $email) {
            $this->postJson('/api/v1/auth/register', [
                'email' => $email,
                'password' => 'Password123',
                'password_confirmation' => 'Password123',
            ])->assertUnprocessable()
                ->assertJsonValidationErrors(['id_token']);

            $this->assertDatabaseMissing('users', ['email' => $email]);
        }
    }

    public function test_existing_account_can_log_in_without_email_verification(): void
    {
        $user = User::factory()->create([
            'email' => 'student@ssct.edu.ph',
            'password' => 'Password123',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Password123',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['token', 'user']])
            ->assertJsonPath('data.user.has_password', true)
            ->assertJsonPath('data.user.average_rating', null)
            ->assertJsonPath('data.user.ratings_count', 0);

        $this->assertTrue($user->fresh()->has_password);
    }

}
