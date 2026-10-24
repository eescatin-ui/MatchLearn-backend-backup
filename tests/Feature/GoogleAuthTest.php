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

    public function test_verified_ssct_google_account_can_create_an_account_and_sign_in(): void
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
            'name' => 'Entered Student Name',
        ])->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'student@ssct.edu.ph')
            ->assertJsonPath('data.user.name', 'Entered Student Name');

        $this->assertDatabaseHas('users', [
            'email' => 'student@ssct.edu.ph',
            'name' => 'Entered Student Name',
        ]);

        $this->withToken($response->json('data.token'))
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.email', 'student@ssct.edu.ph');
    }

    public function test_verified_snsu_google_account_can_create_an_account_and_sign_in(): void
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

        $response = $this->postJson('/api/v1/auth/google', [
            'id_token' => 'google-id-token',
            'name' => 'SNSU Student',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'student@snsu.edu.ph');

        $this->withToken($response->json('data.token'))
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.email', 'student@snsu.edu.ph');
    }

    public function test_google_sign_in_rejects_gmail_accounts(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/tokeninfo*' => Http::sequence()
                ->push([
                    'aud' => 'matchlearn-web-client',
                    'iss' => 'https://accounts.google.com',
                    'exp' => (string) now()->addMinutes(5)->timestamp,
                    'email' => 'student@gmail.com',
                    'email_verified' => 'true',
                    'sub' => 'google-gmail-user-123',
                    'name' => 'Gmail Student',
                ])
                ->push([
                    'aud' => 'matchlearn-web-client',
                    'iss' => 'https://accounts.google.com',
                    'exp' => (string) now()->addMinutes(5)->timestamp,
                    'email' => 'student@googlemail.com',
                    'email_verified' => 'true',
                    'sub' => 'google-googlemail-user-123',
                    'name' => 'Googlemail Student',
                ]),
        ]);

        foreach (['student@gmail.com', 'student@googlemail.com'] as $email) {
            $this->postJson('/api/v1/auth/google', [
                'id_token' => 'google-id-token',
                'name' => 'Gmail Student',
            ])->assertForbidden()
                ->assertJsonPath('message', 'Use a verified SSCT or SNSU student Google account.');

            $this->assertDatabaseMissing('users', ['email' => $email]);
        }
    }

    public function test_unverified_institutional_google_account_cannot_sign_in(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/tokeninfo*' => Http::response([
                'aud' => 'matchlearn-web-client',
                'iss' => 'https://accounts.google.com',
                'exp' => (string) now()->addMinutes(5)->timestamp,
                'email' => 'unverified@ssct.edu.ph',
                'email_verified' => false,
                'sub' => 'google-unverified-user',
            ]),
        ]);

        $this->postJson('/api/v1/auth/google', [
            'id_token' => 'google-id-token',
            'name' => 'Unverified Student',
        ])->assertUnauthorized()
            ->assertJsonPath('message', 'Google sign-in token could not be validated.');

        $this->assertDatabaseMissing('users', ['email' => 'unverified@ssct.edu.ph']);
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

        $this->postJson('/api/v1/auth/google', [
            'id_token' => 'wrong-audience-token',
            'name' => 'Student Name',
        ])
            ->assertUnauthorized();

        User::factory()->create([
            'email' => 'suspended@ssct.edu.ph',
            'is_suspended' => true,
        ]);

        $this->postJson('/api/v1/auth/google', [
            'id_token' => 'suspended-user-token',
            'name' => 'Suspended Student',
        ])
            ->assertForbidden()
            ->assertJsonPath('message', 'Your account has been suspended.');
    }

    public function test_entered_name_updates_an_existing_account_linked_by_google_email(): void
    {
        $user = User::factory()->create([
            'email' => 'student@ssct.edu.ph',
            'name' => 'Old Account Name',
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/tokeninfo*' => Http::response([
                'aud' => 'matchlearn-web-client',
                'iss' => 'https://accounts.google.com',
                'exp' => (string) now()->addMinutes(5)->timestamp,
                'email' => 'student@ssct.edu.ph',
                'email_verified' => true,
                'sub' => 'google-user-123',
                'name' => 'Google Account Name',
            ]),
        ]);

        $response = $this->postJson('/api/v1/auth/google', [
                'id_token' => 'google-id-token',
                'name' => 'New Profile Name',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.name', 'New Profile Name');

        $this->assertSame(1, User::query()->where('email', $user->email)->count());
        $this->withToken($response->json('data.token'))
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.name', 'New Profile Name');
    }

    public function test_google_sign_in_requires_a_full_name(): void
    {
        $this->postJson('/api/v1/auth/google', ['id_token' => 'google-id-token'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }
}
