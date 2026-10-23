<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
     * Register a new student.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        try {
            $user = $this->authService->register(
                $request->input('name'),
                $request->input('email'),
                $request->input('password')
            );

            $token = $user->createToken('mobile-app')->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Registration successful.',
                'data' => [
                    'user' => UserResource::make($user),
                    'token' => $token,
                ],
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        }
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
                    'id_token' => $validated['id_token'],
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
        $email = is_string($claims['email'] ?? null)
            ? mb_strtolower(trim($claims['email']))
            : '';
        $emailVerified = filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $issuer = $claims['iss'] ?? null;
        $expiresAt = filter_var($claims['exp'] ?? null, FILTER_VALIDATE_INT);

        if (
            ($claims['aud'] ?? null) !== $clientId
            || ! in_array($issuer, ['accounts.google.com', 'https://accounts.google.com'], true)
            || ! $expiresAt
            || $expiresAt <= now()->timestamp
            || ! $emailVerified
            || ! is_string($claims['sub'] ?? null)
            || $claims['sub'] === ''
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Google sign-in token could not be validated.',
            ], 401);
        }

        $expectedEmail = isset($validated['expected_email'])
            ? mb_strtolower(trim($validated['expected_email']))
            : null;

        if ($expectedEmail !== null && $expectedEmail !== $email) {
            return response()->json([
                'success' => false,
                'message' => 'The selected Google account does not match the email entered.',
            ], 422);
        }

        if (! preg_match('/@(ssct\.edu\.ph|gmail\.com|googlemail\.com)$/i', $email)) {
            return response()->json([
                'success' => false,
                'message' => 'Use a verified SSCT student or Gmail Google account.',
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
}
