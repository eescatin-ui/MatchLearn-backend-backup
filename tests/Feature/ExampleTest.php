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

    public function test_registration_requires_email_verification(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'email' => 'student@ssct.edu.ph',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);
    }
}
