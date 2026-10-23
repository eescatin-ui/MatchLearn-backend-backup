<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google.client_id' => 'matchlearn-web-client']);
    }

    public function test_verified_ssct_google_account_can_register_and_sign_in(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/tokeninfo*' => Http::response([
                'aud' => 'matchlearn-web-client',
                'iss' => 'https://accounts.google.com',
                'exp' => (string) now()->addMinutes(5)->timestamp,
                'email' => 'student@ssct.edu.ph',
                'email_verified' => 'true',
                'sub' => 'google-user-123',
                'name' => 'SSCT Student',
            ]),
        ]);

        $response = $this->postJson('/api/v1/auth/google', [
            'id_token' => 'google-id-token',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'student@ssct.edu.ph')
            ->assertJsonPath('data.user.name', 'SSCT Student');

        $this->assertDatabaseHas('users', [
            'email' => 'student@ssct.edu.ph',
            'name' => 'SSCT Student',
        ]);

        $this->withToken($response->json('data.token'))
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.email', 'student@ssct.edu.ph');
    }

    public function test_google_sign_in_rejects_non_ssct_accounts(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/tokeninfo*' => Http::response([
                'aud' => 'matchlearn-web-client',
                'iss' => 'accounts.google.com',
                'exp' => (string) now()->addMinutes(5)->timestamp,
                'email' => 'student@snsu.edu.ph',
                'email_verified' => true,
                'sub' => 'google-user-456',
                'name' => 'SNSU Student',
            ]),
        ]);

        $this->postJson('/api/v1/auth/google', ['id_token' => 'google-id-token'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Use a verified SSCT student or Gmail Google account.');

        $this->assertDatabaseMissing('users', ['email' => 'student@snsu.edu.ph']);
    }

    public function test_verified_gmail_account_can_register_only_when_it_matches_the_entered_email(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/tokeninfo*' => Http::response([
                'aud' => 'matchlearn-web-client',
                'iss' => 'https://accounts.google.com',
                'exp' => (string) now()->addMinutes(5)->timestamp,
                'email' => 'student@gmail.com',
                'email_verified' => 'true',
                'sub' => 'google-gmail-user-123',
                'name' => 'Gmail Student',
            ]),
        ]);

        $this->postJson('/api/v1/auth/google', [
            'id_token' => 'google-id-token',
            'expected_email' => 'another@gmail.com',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'The selected Google account does not match the email entered.');

        $this->assertDatabaseMissing('users', ['email' => 'student@gmail.com']);

        $this->postJson('/api/v1/auth/google', [
            'id_token' => 'google-id-token',
            'expected_email' => 'STUDENT@gmail.com',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'student@gmail.com');

        $this->assertDatabaseHas('users', ['email' => 'student@gmail.com']);
    }

    public function test_unverified_gmail_account_cannot_register(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/tokeninfo*' => Http::response([
                'aud' => 'matchlearn-web-client',
                'iss' => 'https://accounts.google.com',
                'exp' => (string) now()->addMinutes(5)->timestamp,
                'email' => 'unverified@gmail.com',
                'email_verified' => false,
                'sub' => 'google-unverified-user',
            ]),
        ]);

        $this->postJson('/api/v1/auth/google', [
            'id_token' => 'google-id-token',
            'expected_email' => 'unverified@gmail.com',
        ])->assertUnauthorized()
            ->assertJsonPath('message', 'Google sign-in token could not be validated.');

        $this->assertDatabaseMissing('users', ['email' => 'unverified@gmail.com']);
    }

    public function test_google_sign_in_rejects_invalid_audience_and_suspended_accounts(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/tokeninfo*' => Http::sequence()
                ->push([
                    'aud' => 'another-client',
                    'iss' => 'accounts.google.com',
                    'exp' => (string) now()->addMinutes(5)->timestamp,
                    'email' => 'student@ssct.edu.ph',
                    'email_verified' => true,
                    'sub' => 'google-user-789',
                ])
                ->push([
                    'aud' => 'matchlearn-web-client',
                    'iss' => 'accounts.google.com',
                    'exp' => (string) now()->addMinutes(5)->timestamp,
                    'email' => 'suspended@ssct.edu.ph',
                    'email_verified' => true,
                    'sub' => 'google-user-101',
                ]),
        ]);

        $this->postJson('/api/v1/auth/google', ['id_token' => 'wrong-audience-token'])
            ->assertUnauthorized();

        User::factory()->create([
            'email' => 'suspended@ssct.edu.ph',
            'is_suspended' => true,
        ]);

        $this->postJson('/api/v1/auth/google', ['id_token' => 'suspended-user-token'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Your account has been suspended.');
    }
}
