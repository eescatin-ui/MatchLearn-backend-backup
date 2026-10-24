<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    protected AuthService $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * Register with a password after Google verifies the institutional email.
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id_token' => ['required', 'string', 'max:8192'],
            'expected_email' => ['required', 'string', 'email', 'max:255'],
            'password' => [
                'required',
                'string',
                'min:8',
                'regex:/^(?=.*[A-Za-z])(?=.*\d).+$/',
                'confirmed',
            ],
        ]);

        $claims = $this->verifyGoogleIdToken($validated['id_token']);
        if ($claims instanceof JsonResponse) {
            return $claims;
        }

        $email = mb_strtolower(trim($claims['email']));
        $expectedEmail = mb_strtolower(trim($validated['expected_email']));

        if ($expectedEmail !== $email) {
            return response()->json([
                'success' => false,
                'message' => 'The selected Google account does not match the email entered.',
            ], 422);
        }

        if (! $this->authService->isInstitutionalEmail($email)) {
            return response()->json([
                'success' => false,
                'message' => 'Use a verified SSCT or SNSU student Google account.',
            ], 403);
        }

        if (User::query()->where('email', $email)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'An account with this email already exists. Sign in instead.',
            ], 409);
        }

        $name = is_string($claims['name'] ?? null) && trim($claims['name']) !== ''
            ? trim($claims['name'])
            : Str::before($email, '@');

        $user = DB::transaction(function () use ($name, $email, $validated): User {
            return User::create([
                'name' => $name,
                'email' => $email,
                'password' => $validated['password'],
                'has_password' => true,
                'is_suspended' => false,
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Registration successful.',
            'data' => [
                'user' => UserResource::make($user),
                'token' => $user->createToken('mobile-app')->plainTextToken,
            ],
        ], 201);
    }

    /**
     * Login with email and password.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $user = $this->authService->login(
                $request->input('email'),
                $request->input('password')
            );

            $token = $user->createToken('mobile-app')->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Login successful.',
                'data' => [
                    'user' => UserResource::make($user),
                    'token' => $token,
                ],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 401);
        }
    }

    public function google(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id_token' => ['required', 'string', 'max:8192'],
            'expected_email' => ['sometimes', 'required', 'string', 'email', 'max:255'],
        ]);
        $claims = $this->verifyGoogleIdToken($validated['id_token']);
        if ($claims instanceof JsonResponse) {
            return $claims;
        }

        $email = mb_strtolower(trim($claims['email']));

        $expectedEmail = isset($validated['expected_email'])
            ? mb_strtolower(trim($validated['expected_email']))
            : null;

        if ($expectedEmail !== null && $expectedEmail !== $email) {
            return response()->json([
                'success' => false,
                'message' => 'The selected Google account does not match the email entered.',
            ], 422);
        }

        if (! $this->authService->isInstitutionalEmail($email)) {
            return response()->json([
                'success' => false,
                'message' => 'Use a verified SSCT or SNSU student Google account.',
            ], 403);
        }

        $user = User::query()->where('email', $email)->first();

        if ($user?->is_suspended) {
            return response()->json([
                'success' => false,
                'message' => 'Your account has been suspended.',
            ], 403);
        }

        if (! $user) {
            $name = is_string($claims['name'] ?? null) && trim($claims['name']) !== ''
                ? trim($claims['name'])
                : Str::before($email, '@');
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => Str::random(64),
                'has_password' => false,
                'is_suspended' => false,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Google login successful.',
            'data' => [
                'user' => UserResource::make($user),
                'token' => $user->createToken('mobile-app')->plainTextToken,
            ],
        ]);
    }

    /**
     * Get current authenticated user.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'User retrieved successfully.',
            'data' => [
                'user' => UserResource::make($request->user()),
            ],
        ]);
    }

    /**
     * Change the authenticated user's password after verifying their current password.
     */
    public function changePassword(Request $request): JsonResponse
    {
        if (! $request->user()->has_password) {
            return response()->json([
                'success' => false,
                'message' => 'Verify your SSCT or SNSU Google account to set a password.',
            ], 409);
        }

        $validated = $request->validate([
            'current_password' => ['required', 'string', 'current_password'],
            'password' => [
                'required',
                'string',
                'min:8',
                'regex:/^(?=.*[A-Za-z])(?=.*\d).+$/',
                'confirmed',
                'different:current_password',
            ],
        ]);

        $request->user()->forceFill([
            'password' => Hash::make($validated['password']),
        ])->save();

        return response()->json([
            'success' => true,
            'message' => 'Password changed successfully.',
        ]);
    }

    /**
     * Set a password for an account that has not authenticated with a password yet.
     */
    public function setPasswordWithGoogle(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id_token' => ['required', 'string', 'max:8192'],
            'password' => [
                'required',
                'string',
                'min:8',
                'regex:/^(?=.*[A-Za-z])(?=.*\d).+$/',
                'confirmed',
            ],
        ]);

        $user = $request->user();
        if ($user->has_password) {
            return response()->json([
                'success' => false,
                'message' => 'This account already has a password. Use the current-password change flow.',
            ], 409);
        }

        $claims = $this->verifyGoogleIdToken($validated['id_token']);
        if ($claims instanceof JsonResponse) {
            return $claims;
        }

        $email = mb_strtolower(trim($claims['email']));
        if (
            ! $this->authService->isInstitutionalEmail($email)
            || $email !== mb_strtolower(trim($user->email))
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Verify the same SSCT or SNSU Google account used for this MatchLearn account.',
            ], 403);
        }

        $user->forceFill([
            'password' => Hash::make($validated['password']),
            'has_password' => true,
        ])->save();

        return response()->json([
            'success' => true,
            'message' => 'Password set successfully.',
        ]);
    }

    /**
     * Logout current device (revoke token).
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully.',
        ]);
    }

    /**
     * Logout all devices (revoke all tokens).
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out from all devices.',
        ]);
    }

    /**
     * Permanently delete the authenticated user's account and associated data.
     */
    public function deleteAccount(Request $request): JsonResponse
    {
        $user = $request->user();

        DB::transaction(function () use ($user): void {
            $user->tokens()->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->delete();
        });

        return response()->json([
            'success' => true,
            'message' => 'Account deleted successfully.',
        ]);
    }

    /**
     * @return array<string, mixed>|JsonResponse
     */
    private function verifyGoogleIdToken(string $idToken): array|JsonResponse
    {
        $clientId = config('services.google.client_id');
        if (! is_string($clientId) || $clientId === '') {
            return response()->json([
                'success' => false,
                'message' => 'Google sign-in is not configured on the server.',
            ], 503);
        }

        try {
            $verification = Http::acceptJson()
                ->timeout(10)
                ->get('https://oauth2.googleapis.com/tokeninfo', [
                    'id_token' => $idToken,
                ]);
        } catch (ConnectionException) {
            return response()->json([
                'success' => false,
                'message' => 'Google sign-in could not be verified right now. Please try again.',
            ], 503);
        }

        if (! $verification->successful()) {
            return response()->json([
                'success' => false,
                'message' => 'Google sign-in token is invalid or expired.',
            ], 401);
        }

        $claims = $verification->json();
        if (! is_array($claims)) {
            return response()->json([
                'success' => false,
                'message' => 'Google sign-in token could not be validated.',
            ], 401);
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
            return response()->json([
                'success' => false,
                'message' => 'Google sign-in token could not be validated.',
            ], 401);
        }

        return $claims;
    }
}
