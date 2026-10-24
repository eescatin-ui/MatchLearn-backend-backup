<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Hash;
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
            ->assertJsonPath('data.user.name', 'SSCT Student')
            ->assertJsonPath('data.user.has_password', false);

        $this->assertDatabaseHas('users', [
            'email' => 'student@ssct.edu.ph',
            'name' => 'SSCT Student',
        ]);

        $this->withToken($response->json('data.token'))
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.email', 'student@ssct.edu.ph');
    }

    public function test_verified_snsu_google_account_can_register_and_sign_in(): void
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

        $response = $this->postJson('/api/v1/auth/google', ['id_token' => 'google-id-token'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'student@snsu.edu.ph');

        $this->withToken($response->json('data.token'))
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.email', 'student@snsu.edu.ph');
    }

    public function test_register_endpoint_creates_password_account_only_after_google_verification(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/tokeninfo*' => Http::response([
                'aud' => 'matchlearn-web-client',
                'iss' => 'https://accounts.google.com',
                'exp' => (string) now()->addMinutes(5)->timestamp,
                'email' => 'student@ssct.edu.ph',
                'email_verified' => true,
                'sub' => 'google-register-user',
                'name' => 'SSCT Student',
            ]),
        ]);

        $response = $this->postJson('/api/v1/auth/register', [
            'id_token' => 'google-id-token',
            'expected_email' => 'student@ssct.edu.ph',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'student@ssct.edu.ph')
            ->assertJsonPath('data.user.name', 'SSCT Student')
            ->assertJsonPath('data.user.has_password', true)
            ->assertJsonStructure(['data' => ['token', 'user']]);

        $user = User::query()->where('email', 'student@ssct.edu.ph')->firstOrFail();
        $this->assertTrue(Hash::check('Password123', $user->password));

        $this->withToken($response->json('data.token'))
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.email', 'student@ssct.edu.ph');
    }

    public function test_register_endpoint_rejects_google_email_mismatch_and_non_institutional_domains(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/tokeninfo*' => Http::sequence()
                ->push([
                    'aud' => 'matchlearn-web-client',
                    'iss' => 'https://accounts.google.com',
                    'exp' => (string) now()->addMinutes(5)->timestamp,
                    'email' => 'student@gmail.com',
                    'email_verified' => true,
                    'sub' => 'google-gmail-register-user',
                ])
                ->push([
                    'aud' => 'matchlearn-web-client',
                    'iss' => 'https://accounts.google.com',
                    'exp' => (string) now()->addMinutes(5)->timestamp,
                    'email' => 'student@ssct.edu.ph',
                    'email_verified' => true,
                    'sub' => 'google-ssct-register-user',
                ]),
        ]);

        $this->postJson('/api/v1/auth/register', [
            'id_token' => 'gmail-token',
            'expected_email' => 'student@gmail.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertForbidden()
            ->assertJsonPath('message', 'Use a verified SSCT or SNSU student Google account.');

        $this->postJson('/api/v1/auth/register', [
            'id_token' => 'ssct-token',
            'expected_email' => 'another@ssct.edu.ph',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'The selected Google account does not match the email entered.');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_register_endpoint_rejects_existing_accounts(): void
    {
        User::factory()->create(['email' => 'student@ssct.edu.ph']);
        Http::fake([
            'https://oauth2.googleapis.com/tokeninfo*' => Http::response([
                'aud' => 'matchlearn-web-client',
                'iss' => 'https://accounts.google.com',
                'exp' => (string) now()->addMinutes(5)->timestamp,
                'email' => 'student@ssct.edu.ph',
                'email_verified' => true,
                'sub' => 'google-existing-register-user',
            ]),
        ]);

        $this->postJson('/api/v1/auth/register', [
            'id_token' => 'google-id-token',
            'expected_email' => 'student@ssct.edu.ph',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertConflict()
            ->assertJsonPath('message', 'An account with this email already exists. Sign in instead.');

        $this->assertDatabaseCount('users', 1);
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
                'expected_email' => $email,
            ])->assertForbidden()
                ->assertJsonPath('message', 'Use a verified SSCT or SNSU student Google account.');

            $this->assertDatabaseMissing('users', ['email' => $email]);
        }
    }

    public function test_unverified_institutional_google_account_cannot_register(): void
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
            'expected_email' => 'unverified@ssct.edu.ph',
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

    public function test_google_only_user_can_set_password_after_reverifying_the_same_institutional_account(): void
    {
        $user = User::factory()->create([
            'email' => 'student@ssct.edu.ph',
            'has_password' => false,
        ]);
        $token = $user->createToken('mobile-app')->plainTextToken;

        Http::fake([
            'https://oauth2.googleapis.com/tokeninfo*' => Http::response([
                'aud' => 'matchlearn-web-client',
                'iss' => 'https://accounts.google.com',
                'exp' => (string) now()->addMinutes(5)->timestamp,
                'email' => 'student@ssct.edu.ph',
                'email_verified' => true,
                'sub' => 'google-user-123',
            ]),
        ]);

        $this->withToken($token)
            ->postJson('/api/v1/auth/set-password-with-google', [
                'id_token' => 'google-id-token',
                'password' => 'NewPassword456',
                'password_confirmation' => 'NewPassword456',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Password set successfully.');

        $this->assertTrue(Hash::check('NewPassword456', $user->fresh()->password));
        $this->assertTrue($user->fresh()->has_password);
    }

    public function test_google_only_user_cannot_set_password_with_another_google_account(): void
    {
        $user = User::factory()->create([
            'email' => 'student@ssct.edu.ph',
            'has_password' => false,
        ]);
        $token = $user->createToken('mobile-app')->plainTextToken;

        Http::fake([
            'https://oauth2.googleapis.com/tokeninfo*' => Http::response([
                'aud' => 'matchlearn-web-client',
                'iss' => 'https://accounts.google.com',
                'exp' => (string) now()->addMinutes(5)->timestamp,
                'email' => 'other@snsu.edu.ph',
                'email_verified' => true,
                'sub' => 'other-google-user',
            ]),
        ]);

        $this->withToken($token)
            ->postJson('/api/v1/auth/set-password-with-google', [
                'id_token' => 'other-google-id-token',
                'password' => 'NewPassword456',
                'password_confirmation' => 'NewPassword456',
            ])
            ->assertForbidden()
            ->assertJsonPath(
                'message',
                'Verify the same SSCT or SNSU Google account used for this MatchLearn account.'
            );

        $this->assertFalse($user->fresh()->has_password);
    }

    public function test_google_verification_cannot_replace_a_password_that_is_already_set(): void
    {
        $user = User::factory()->create([
            'email' => 'student@ssct.edu.ph',
            'password' => 'OldPassword123',
            'has_password' => true,
        ]);
        $token = $user->createToken('mobile-app')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/auth/set-password-with-google', [
                'id_token' => 'google-id-token',
                'password' => 'NewPassword456',
                'password_confirmation' => 'NewPassword456',
            ])
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertTrue(Hash::check('OldPassword123', $user->fresh()->password));
    }
}
