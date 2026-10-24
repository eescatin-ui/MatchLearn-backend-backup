<?php

namespace App\Services;

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

}
