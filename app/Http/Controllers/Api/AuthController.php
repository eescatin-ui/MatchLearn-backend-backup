<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuthService;
use App\Services\GoogleIdTokenVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class AuthController extends Controller
{
    protected AuthService $authService;
    protected GoogleIdTokenVerifier $googleIdTokenVerifier;

    public function __construct(AuthService $authService, GoogleIdTokenVerifier $googleIdTokenVerifier)
    {
        $this->authService = $authService;
        $this->googleIdTokenVerifier = $googleIdTokenVerifier;
    }

    public function google(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id_token' => ['required', 'string', 'max:8192'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
        ]);
        $name = trim($validated['name']);
        if ($name === '') {
            return response()->json([
                'success' => false,
                'message' => 'Enter your full name.',
            ], 422);
        }

        try {
            $claims = $this->googleIdTokenVerifier->verify($validated['id_token']);
        } catch (RuntimeException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], $exception->getCode() ?: 401);
        }

        $email = mb_strtolower(trim($claims['email']));

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
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => Str::random(64),
                'is_suspended' => false,
            ]);
        } elseif ($user->name !== $name) {
            $user->forceFill(['name' => $name])->save();
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
