<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BuddyRequest;
use App\Models\BuddyReview;
use App\Models\StudySession;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StudySessionReviewController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $reviewUserId = $request->integer('review_user_id') ?: $user->id;
        $isOwnReviews = $reviewUserId === $user->id;

        if (! $isOwnReviews && ! $this->areBuddies($user->id, $reviewUserId)) {
            abort(404);
        }

        $reviews = BuddyReview::query()
            ->where('reviewed_user_id', $reviewUserId)
            ->with('reviewer:id,name', 'reviewer.profile:id,user_id,profile_photo_url')
            ->latest()
            ->get()
            ->map(fn (BuddyReview $review) => [
                'id' => $review->id,
                'rating' => $review->rating,
                'comment' => $review->comment,
                'reviewer_name' => $review->reviewer->name,
                'reviewer_profile_photo_url' => $review->reviewer->profile?->profile_photo_url,
                'created_at' => $review->created_at->toIso8601String(),
            ]);

        $myReview = BuddyReview::query()
            ->where('reviewer_user_id', $user->id)
            ->where('reviewed_user_id', $reviewUserId)
            ->first(['rating', 'comment']);

        $reviewableBuddies = collect();
        if ($isOwnReviews) {
            $buddyIds = BuddyRequest::query()
                ->where('status', 'accepted')
                ->where(fn ($query) => $query
                    ->where('requester_id', $user->id)
                    ->orWhere('recipient_id', $user->id))
                ->get(['requester_id', 'recipient_id'])
                ->map(fn (BuddyRequest $request) => $request->requester_id === $user->id
                    ? $request->recipient_id
                    : $request->requester_id)
                ->unique()
                ->values();

            $myRatings = BuddyReview::query()
                ->where('reviewer_user_id', $user->id)
                ->whereIn('reviewed_user_id', $buddyIds)
                ->get()
                ->keyBy('reviewed_user_id');

            $reviewableBuddies = User::query()
                ->with('profile:id,user_id,profile_photo_url')
                ->whereIn('id', $buddyIds)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $buddy) => [
                    'id' => $buddy->id,
                    'name' => $buddy->name,
                    'profile_photo_url' => $buddy->profile?->profile_photo_url,
                    'my_rating' => $myRatings->get($buddy->id)?->rating,
                    'my_comment' => $myRatings->get($buddy->id)?->comment,
                ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Reviews retrieved successfully.',
            'data' => [
                'reviews' => $reviews,
                'reviewable_buddies' => $reviewableBuddies,
                'my_review' => $myReview,
                'average_rating' => $reviews->isEmpty() ? null : round($reviews->avg('rating'), 1),
                'ratings_count' => $reviews->count(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reviewed_user_id' => ['required', 'integer', 'exists:users,id', Rule::notIn([$request->user()->id])],
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $user = $request->user();
        $reviewedUserId = (int) $validated['reviewed_user_id'];
        abort_unless($this->areBuddies($user->id, $reviewedUserId), 403);
        $hasCompletedSession = StudySession::query()
            ->where('status', 'completed')
            ->whereNull('study_group_id')
            ->where(fn ($query) => $query
                ->where(fn ($pair) => $pair->where('host_user_id', $user->id)->where('participant_user_id', $reviewedUserId))
                ->orWhere(fn ($pair) => $pair->where('host_user_id', $reviewedUserId)->where('participant_user_id', $user->id)))
            ->exists();
        if (! $hasCompletedSession) {
            return response()->json([
                'success' => false,
                'message' => 'Complete at least one study session with this buddy before rating them.',
            ], 403);
        }

        $existingReview = BuddyReview::query()
            ->where('reviewer_user_id', $user->id)
            ->where('reviewed_user_id', $reviewedUserId)
            ->first();

        $review = BuddyReview::updateOrCreate([
            'reviewer_user_id' => $user->id,
            'reviewed_user_id' => $reviewedUserId,
        ], [
            'rating' => $validated['rating'],
            'comment' => $validated['comment'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => $existingReview ? 'Review updated.' : 'Review submitted.',
            'data' => ['review_id' => $review->id],
        ], $existingReview ? 200 : 201);
    }

    private function areBuddies(int $userId, int $buddyId): bool
    {
        return BuddyRequest::query()
            ->where('status', 'accepted')
            ->where(fn ($query) => $query
                ->where(fn ($pair) => $pair->where('requester_id', $userId)->where('recipient_id', $buddyId))
                ->orWhere(fn ($pair) => $pair->where('requester_id', $buddyId)->where('recipient_id', $userId)))
            ->exists();
    }
}
