<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_delete_their_account_and_associated_profile(): void
    {
        $user = User::factory()->create();
        $user->profile()->create(['institution' => 'SSCT']);
        $plainTextToken = $user->createToken('mobile-app')->plainTextToken;

        $this->withToken($plainTextToken)
            ->deleteJson('/api/v1/auth/account')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Account deleted successfully.');

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('profiles', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'mobile-app',
        ]);
    }

    public function test_account_deletion_requires_authentication(): void
    {
        $this->deleteJson('/api/v1/auth/account')->assertUnauthorized();
    }
}
