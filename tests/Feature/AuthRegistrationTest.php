<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AuthRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_registration_and_password_login_routes_are_removed(): void
    {
        $this->postJson('/api/v1/auth/register', [])->assertNotFound();
        $this->postJson('/api/v1/auth/register/verification-code', [])->assertNotFound();
        $this->postJson('/api/v1/auth/login', [])->assertNotFound();

        $this->assertFalse(Schema::hasTable('registration_verification_codes'));
        $this->assertFalse(Schema::hasColumn('users', 'has_password'));
    }

    public function test_password_management_routes_are_removed(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('mobile-app')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/auth/change-password', [])
            ->assertNotFound();
        $this->withToken($token)
            ->postJson('/api/v1/auth/set-password-with-google', [])
            ->assertNotFound();
    }
}
