<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public const INSTITUTIONAL_DOMAINS = [
        'snsu.edu.ph',
        'ssct.edu.ph',
    ];

    /**
     * Validate if email is from institutional domain.
     */
    public function isInstitutionalEmail(string $email): bool
    {
        $domain = mb_strtolower(trim(explode('@', $email)[1] ?? ''));
        return in_array($domain, self::INSTITUTIONAL_DOMAINS, true);
    }

    /**
     * Normalize email (lowercase, trim).
     */
    public function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * Validate password strength.
     */
    public function isValidPassword(string $password): bool
    {
        // Minimum 8 characters, at least one letter, at least one number
        return strlen($password) >= 8 &&
               preg_match('/[a-zA-Z]/', $password) &&
               preg_match('/[0-9]/', $password);
    }

    /**
     * Register a new user.
     */
    public function register(string $name, string $email, string $password): User
    {
        $email = $this->normalizeEmail($email);

        if (!$this->isInstitutionalEmail($email)) {
            throw ValidationException::withMessages([
                'email' => 'Only SNSU and SSCT student emails can register for MatchLearn.',
            ]);
        }

        if (!$this->isValidPassword($password)) {
            throw ValidationException::withMessages([
                'password' => 'Password must be at least 8 characters with at least one letter and one number.',
            ]);
        }

        if (User::where('email', $email)->exists()) {
            throw ValidationException::withMessages([
                'email' => 'This email is already registered.',
            ]);
        }

        return User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'is_suspended' => false,
        ]);
    }

    /**
     * Attempt login.
     */
    public function login(string $email, string $password): User
    {
        $email = $this->normalizeEmail($email);

        if (!$this->isInstitutionalEmail($email)) {
            throw ValidationException::withMessages([
                'email' => 'Invalid credentials.',
            ]);
        }

        $user = User::where('email', $email)->first();

        if (!$user || !Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'Invalid credentials.',
            ]);
        }

        if ($user->is_suspended) {
            throw ValidationException::withMessages([
                'email' => 'Your account has been suspended.',
            ]);
        }

        return $user;
    }

}
