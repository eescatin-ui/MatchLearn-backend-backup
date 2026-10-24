<?php

namespace Tests\Feature;

use App\Mail\RegistrationVerificationCodeMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AuthRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_requires_an_email_verification_code(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'email' => 'student@ssct.edu.ph',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_only_institutional_addresses_can_request_an_email_code(): void
    {
        Mail::fake();

        $this->postJson('/api/v1/auth/register/verification-code', ['email' => 'student@gmail.com'])
            ->assertForbidden();
        $this->postJson('/api/v1/auth/register/verification-code', ['email' => 'STUDENT@SSCT.EDU.PH'])
            ->assertOk();

        Mail::assertSent(RegistrationVerificationCodeMail::class, fn ($mail): bool => $mail->hasTo('student@ssct.edu.ph'));
        $this->assertDatabaseHas('registration_verification_codes', ['email' => 'student@ssct.edu.ph']);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_verification_code_cannot_be_resent_before_the_cooldown(): void
    {
        Mail::fake();
        $this->postJson('/api/v1/auth/register/verification-code', ['email' => 'student@ssct.edu.ph'])
            ->assertOk();
        $this->postJson('/api/v1/auth/register/verification-code', ['email' => 'student@ssct.edu.ph'])
            ->assertTooManyRequests();

        Mail::assertSentTimes(RegistrationVerificationCodeMail::class, 1);
    }

    public function test_user_is_created_only_after_the_email_code_is_verified(): void
    {
        Mail::fake();
        $this->postJson('/api/v1/auth/register/verification-code', ['email' => 'student@ssct.edu.ph'])
            ->assertOk();

        $code = null;
        Mail::assertSent(RegistrationVerificationCodeMail::class, function ($mail) use (&$code): bool {
            $code = $mail->code;
            return true;
        });
        $this->assertNotNull($code);
        $this->assertTrue(Hash::check($code, DB::table('registration_verification_codes')->where('email', 'student@ssct.edu.ph')->value('code_hash')));
        $wrongCode = $code === '000000' ? '000001' : '000000';

        $this->postJson('/api/v1/auth/register', [
            'email' => 'student@ssct.edu.ph',
            'code' => $wrongCode,
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('users', 0);

        $response = $this->postJson('/api/v1/auth/register', [
            'email' => 'student@ssct.edu.ph',
            'code' => $code,
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'student@ssct.edu.ph')
            ->assertJsonPath('data.user.has_password', true);

        $user = User::query()->where('email', 'student@ssct.edu.ph')->firstOrFail();
        $this->assertTrue(Hash::check('Password123', $user->password));
        $this->assertDatabaseMissing('registration_verification_codes', ['email' => 'student@ssct.edu.ph']);
        $this->withToken($response->json('data.token'))
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.email', 'student@ssct.edu.ph');
    }

    public function test_verification_code_attempts_are_limited(): void
    {
        Mail::fake();
        $this->postJson('/api/v1/auth/register/verification-code', ['email' => 'student@snsu.edu.ph'])
            ->assertOk();
        $code = null;
        Mail::assertSent(RegistrationVerificationCodeMail::class, function ($mail) use (&$code): bool {
            $code = $mail->code;
            return true;
        });
        $wrongCode = $code === '000000' ? '000001' : '000000';

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/register', [
                'email' => 'student@snsu.edu.ph',
                'code' => $wrongCode,
                'password' => 'Password123',
                'password_confirmation' => 'Password123',
            ])->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/register', [
            'email' => 'student@snsu.edu.ph',
            'code' => $wrongCode,
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertTooManyRequests();
    }

    public function test_expired_verification_codes_cannot_create_an_account(): void
    {
        Mail::fake();
        $this->postJson('/api/v1/auth/register/verification-code', ['email' => 'student@snsu.edu.ph'])
        ->assertOk();
        $code = null;
        Mail::assertSent(RegistrationVerificationCodeMail::class, function ($mail) use (&$code): bool {
        $code = $mail->code;
        return true;
        });
        $this->travelTo(now()->addMinutes(11));

        $this->postJson('/api/v1/auth/register', [
        'email' => 'student@snsu.edu.ph',
        'code' => $code,
        'password' => 'Password123',
        'password_confirmation' => 'Password123',
        ])->assertUnprocessable()
        ->assertJsonPath('message', 'Your verification code has expired. Request a new code.');
        $this->assertDatabaseCount('users', 0);
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
