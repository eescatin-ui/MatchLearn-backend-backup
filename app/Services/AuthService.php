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
