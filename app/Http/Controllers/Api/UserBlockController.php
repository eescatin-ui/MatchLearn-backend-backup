<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BuddyRequest;
use App\Models\DirectConversation;
use App\Models\StudyMatch;
use App\Models\UserBlock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UserBlockController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $blocks = UserBlock::query()
            ->where('blocker_user_id', $request->user()->id)
            ->with('blockedUser:id,name')
            ->latest()
            ->get()
            ->map(fn (UserBlock $block): array => [
                'user_id' => $block->blocked_user_id,
                'name' => $block->blockedUser->name,
            ])
            ->values();

        return response()->json([
            'success' => true,
            'data' => ['blocked_users' => $blocks],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('users', 'id'), Rule::notIn([$request->user()->id])],
        ]);
        $currentUserId = $request->user()->id;
        $blockedUserId = (int) $validated['user_id'];

        DB::transaction(function () use ($currentUserId, $blockedUserId): void {
            UserBlock::query()->firstOrCreate([
                'blocker_user_id' => $currentUserId,
                'blocked_user_id' => $blockedUserId,
            ]);

            BuddyRequest::query()
                ->where(fn ($query) => $query
                    ->where('requester_id', $currentUserId)
                    ->where('recipient_id', $blockedUserId))
                ->orWhere(fn ($query) => $query
                    ->where('requester_id', $blockedUserId)
                    ->where('recipient_id', $currentUserId))
                ->delete();

            StudyMatch::query()
                ->where(fn ($query) => $query
                    ->where('user_id', $currentUserId)
                    ->where('matched_user_id', $blockedUserId))
                ->orWhere(fn ($query) => $query
                    ->where('user_id', $blockedUserId)
                    ->where('matched_user_id', $currentUserId))
                ->delete();

            DirectConversation::query()
                ->where('user_one_id', min($currentUserId, $blockedUserId))
                ->where('user_two_id', max($currentUserId, $blockedUserId))
                ->delete();
        });

        return response()->json([
            'success' => true,
            'message' => 'User blocked. They will no longer appear in your matches or direct conversations.',
        ], 201);
    }

    public function destroy(Request $request, int $userId): JsonResponse
    {
        $deleted = UserBlock::query()
            ->where('blocker_user_id', $request->user()->id)
            ->where('blocked_user_id', $userId)
            ->delete();

        if ($deleted === 0) {
            return response()->json([
                'success' => false,
                'message' => 'Blocked user not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'User unblocked. They may appear in recommendations again after matches refresh.',
        ]);
    }
}
