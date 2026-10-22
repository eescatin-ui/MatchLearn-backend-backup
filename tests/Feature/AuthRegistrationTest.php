<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_authenticates_new_account_immediately(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Student User',
            'email' => 'student@snsu.edu.ph',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'student@snsu.edu.ph');

        $token = $response->json('data.token');
        $this->assertNotEmpty($token);

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.email', 'student@snsu.edu.ph');
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
            ->assertJsonStructure(['data' => ['token', 'user']]);
    }
}
