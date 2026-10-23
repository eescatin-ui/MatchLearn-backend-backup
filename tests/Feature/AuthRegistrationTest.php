<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_only_registration_is_rejected_for_institutional_domains(): void
    {
        foreach (['student@ssct.edu.ph', 'student@snsu.edu.ph'] as $email) {
            $this->postJson('/api/v1/auth/register', [
                'name' => 'Student User',
                'email' => $email,
                'password' => 'Password123',
                'password_confirmation' => 'Password123',
            ])->assertForbidden()
                ->assertJsonPath(
                    'message',
                    'New accounts must be created using a verified SSCT or SNSU Google account.'
                );

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
            ->assertJsonStructure(['data' => ['token', 'user']]);
    }

    public function test_registration_rejects_non_institutional_email_domains(): void
    {
        foreach ([
            'student@ssct.edu.ph',
            'student@snsu.edu.ph',
            'student@gmail.com',
            'student@googlemail.com',
            'student@example.com',
        ] as $email) {
            $this->postJson('/api/v1/auth/register', [
                'name' => 'Non-institutional User',
                'email' => $email,
                'password' => 'Password123',
                'password_confirmation' => 'Password123',
            ])->assertForbidden()
                ->assertJsonPath(
                    'message',
                    'New accounts must be created using a verified SSCT or SNSU Google account.'
                );

            $this->assertDatabaseMissing('users', ['email' => $email]);
        }
    }
}
