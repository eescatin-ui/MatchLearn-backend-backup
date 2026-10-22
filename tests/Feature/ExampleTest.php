<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }

    public function test_register_accepts_confirm_password_alias(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Test Student',
            'email' => 'student@ssct.edu.ph',
            'password' => 'Password123',
            'confirmPassword' => 'Password123',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);
    }
}
