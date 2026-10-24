<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GoogleIdTokenVerifier
{
    /**
     * @return array<string, mixed>
     */
    public function verify(string $idToken): array
    {
        $clientId = config('services.google.client_id');
        if (! is_string($clientId) || $clientId === '') {
            throw new RuntimeException('Google sign-in is not configured on the server.', 503);
        }

        try {
            $verification = Http::acceptJson()
                ->timeout(10)
                ->get('https://oauth2.googleapis.com/tokeninfo', [
                    'id_token' => $idToken,
                ]);
        } catch (ConnectionException) {
            throw new RuntimeException('Google sign-in could not be verified right now. Please try again.', 503);
        }

        if (! $verification->successful()) {
            throw new RuntimeException('Google sign-in token is invalid or expired.', 401);
        }

        $claims = $verification->json();
        if (! is_array($claims)) {
            throw new RuntimeException('Google sign-in token could not be validated.', 401);
        }

        $emailVerified = filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $expiresAt = filter_var($claims['exp'] ?? null, FILTER_VALIDATE_INT);

        if (
            ($claims['aud'] ?? null) !== $clientId
            || ! in_array($claims['iss'] ?? null, ['accounts.google.com', 'https://accounts.google.com'], true)
            || ! $expiresAt
            || $expiresAt <= now()->timestamp
            || ! $emailVerified
            || ! is_string($claims['email'] ?? null)
            || ! is_string($claims['sub'] ?? null)
            || $claims['sub'] === ''
        ) {
            throw new RuntimeException('Google sign-in token could not be validated.', 401);
        }

        return $claims;
    }
}
