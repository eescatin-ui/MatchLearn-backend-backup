<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BuddyRequest;
use App\Models\StudyMatch;
use App\Models\User;
use App\Services\ActivityNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BuddyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $relationships = BuddyRequest::query()
            ->where(function ($query) use ($userId): void {
                $query->where('requester_id', $userId)
                    ->orWhere('recipient_id', $userId);
            })
            ->whereIn('status', ['pending', 'accepted'])
            ->with([
                'requester' => fn ($query) => $query
                    ->with(['profile.program'])
                    ->withAvg('buddyReviewsReceived', 'rating')
                    ->withCount('buddyReviewsReceived'),
                'recipient' => fn ($query) => $query
                    ->with(['profile.program'])
                    ->withAvg('buddyReviewsReceived', 'rating')
                    ->withCount('buddyReviewsReceived'),
            ])
            ->get();

        $buddies = [];
        $incoming = [];
        $outgoing = [];

        foreach ($relationships as $relationship) {
            if ($relationship->status === 'accepted') {
                $otherUser = $relationship->requester_id === $userId
                    ? $relationship->recipient
                    : $relationship->requester;
                $buddies[] = $this->userSummary($otherUser);
            } elseif ($relationship->recipient_id === $userId) {
                $incoming[] = [
                    'id' => $relationship->id,
                    'user' => $this->userSummary($relationship->requester),
                ];
            } else {
                $outgoing[] = [
                    'id' => $relationship->id,
                    'user' => $this->userSummary($relationship->recipient),
                ];
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'buddies' => $buddies,
                'incoming_requests' => $incoming,
                'outgoing_requests' => $outgoing,
            ],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $userId = $request->user()->id;
        $isBuddy = BuddyRequest::query()
            ->where('status', 'accepted')
            ->where(fn ($query) => $query
                ->where(fn ($pair) => $pair->where('requester_id', $userId)->where('recipient_id', $id))
                ->orWhere(fn ($pair) => $pair->where('requester_id', $id)->where('recipient_id', $userId)))
            ->exists();

        if (! $isBuddy) {
            return response()->json([
                'success' => false,
                'message' => 'Accepted study buddy not found.',
            ], 404);
        }

        $buddy = User::query()
            ->with(['profile.department', 'profile.program', 'profile.learningStyle', 'profile.studyCourses'])
            ->findOrFail($id);
        $profile = $buddy->profile;

        return response()->json([
            'success' => true,
            'data' => [
                'buddy' => [
                    'id' => $buddy->id,
                    'name' => $buddy->name,
                    'profile' => $profile ? [
                        'institution' => $profile->institution,
                        'gender' => $profile->gender,
                        'marital_status' => $profile->marital_status,
                        'department' => $profile->department?->name,
                        'program' => $profile->program?->name,
                        'year_level' => $profile->year_level,
                        'learning_style' => $profile->learningStyle?->name,
                        'bio' => $profile->bio,
                        'phone_number' => $profile->phone_number_public ? $profile->phone_number : null,
                        'address' => $profile->address_public ? $profile->address : null,
                        'profile_photo_url' => $profile->profile_photo_url,
                        'study_courses' => $profile->studyCourses->map(fn ($course) => [
                            'course_code' => $course->course_code,
                            'normalized_course_code' => $course->course_code_normalized,
                            'course_name' => $course->course_name,
                        ])->values(),
                        'academic_strengths' => $profile->academic_strengths ?? [],
                        'academic_needs' => $profile->academic_needs ?? [],
                        'study_mode' => $profile->study_mode,
                    ] : null,
                ],
            ],
        ]);
    }

    public function destroy(Request $request, int $id, ActivityNotificationService $notifications): JsonResponse
    {
        $userId = $request->user()->id;
        $relationship = BuddyRequest::query()
            ->where('status', 'accepted')
            ->where(fn ($query) => $query
                ->where(fn ($pair) => $pair->where('requester_id', $userId)->where('recipient_id', $id))
                ->orWhere(fn ($pair) => $pair->where('requester_id', $id)->where('recipient_id', $userId)))
            ->first();

        if (! $relationship) {
            return response()->json([
                'success' => false,
                'message' => 'Accepted study buddy not found.',
            ], 404);
        }

        $otherUserId = $relationship->requester_id === $userId
            ? $relationship->recipient_id
            : $relationship->requester_id;
        $relationship->delete();
        $notifications->send(
            $otherUserId,
            "buddy:removed:{$userId}:{$otherUserId}:{$relationship->id}",
            'buddy_removed',
            'Study buddy connection ended',
            "{$request->user()->name} removed you as a study buddy."
        );

        return response()->json([
            'success' => true,
            'message' => 'Study buddy removed.',
        ]);
    }

    public function store(Request $request, ActivityNotificationService $notifications): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);
        $requesterId = $request->user()->id;
        $recipientId = (int) $validated['user_id'];

        if ($requesterId === $recipientId) {
            return response()->json(['success' => false, 'message' => 'You cannot request yourself.'], 422);
        }

        $isRecommendation = StudyMatch::query()
            ->where(fn ($query) => $query->where('user_id', $requesterId)->where('matched_user_id', $recipientId))
            ->orWhere(fn ($query) => $query->where('user_id', $recipientId)->where('matched_user_id', $requesterId))
            ->exists();

        if (! $isRecommendation) {
            return response()->json([
                'success' => false,
                'message' => 'You can only request a student recommended to you.',
            ], 403);
        }

        $existing = BuddyRequest::query()
            ->where(function ($query) use ($requesterId, $recipientId): void {
                $query->where('requester_id', $requesterId)->where('recipient_id', $recipientId);
            })
            ->orWhere(function ($query) use ($requesterId, $recipientId): void {
                $query->where('requester_id', $recipientId)->where('recipient_id', $requesterId);
            })
            ->get();

        if ($existing->contains('status', 'accepted')) {
            return response()->json(['success' => false, 'message' => 'You are already study buddies.'], 409);
        }

        $incomingRequest = $existing->first(
            fn (BuddyRequest $buddyRequest): bool => $buddyRequest->requester_id === $recipientId
                && $buddyRequest->recipient_id === $requesterId
                && $buddyRequest->status === 'pending'
        );
        if ($incomingRequest) {
            return response()->json([
                'success' => false,
                'message' => 'This student has already sent you a request. Accept it from My Buddies.',
            ], 409);
        }

        $buddyRequest = $existing->first(
            fn (BuddyRequest $item): bool => $item->requester_id === $requesterId
                && $item->recipient_id === $recipientId
        );

        if ($buddyRequest && $buddyRequest->status === 'pending') {
            return response()->json([
                'success' => true,
                'message' => 'Buddy request already sent.',
                'data' => ['status' => 'pending_sent'],
            ]);
        }

        if ($buddyRequest) {
            $buddyRequest->update(['status' => 'pending']);
        } else {
            $buddyRequest = BuddyRequest::create([
                'requester_id' => $requesterId,
                'recipient_id' => $recipientId,
                'status' => 'pending',
            ]);
        }
        $notifications->send(
            $recipientId,
            "buddy-request:{$buddyRequest->id}:received:".now()->format('Uu'),
            'buddy_request',
            'New study buddy request',
            "{$request->user()->name} wants to be your study buddy.",
            ['request_id' => $buddyRequest->id]
        );

        return response()->json([
            'success' => true,
            'message' => 'Buddy request sent.',
            'data' => ['request_id' => $buddyRequest->id, 'status' => 'pending_sent'],
        ], 201);
    }

    public function respond(Request $request, int $id, ActivityNotificationService $notifications): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'in:accept,decline'],
        ]);

        $buddyRequest = BuddyRequest::query()
            ->whereKey($id)
            ->where('recipient_id', $request->user()->id)
            ->where('status', 'pending')
            ->first();

        if (! $buddyRequest) {
            return response()->json([
                'success' => false,
                'message' => 'Pending buddy request not found.',
            ], 404);
        }

        DB::transaction(function () use ($buddyRequest, $validated): void {
            $buddyRequest->update(['status' => $validated['action'] === 'accept' ? 'accepted' : 'declined']);
        });
        $accepted = $validated['action'] === 'accept';
        $notifications->send(
            $buddyRequest->requester_id,
            "buddy-request:{$buddyRequest->id}:{$validated['action']}:".now()->format('Uu'),
            $accepted ? 'buddy_request_accepted' : 'buddy_request_declined',
            $accepted ? 'Buddy request accepted' : 'Buddy request declined',
            $accepted
                ? "{$request->user()->name} accepted your study buddy request."
                : "{$request->user()->name} declined your study buddy request.",
            ['user_id' => $request->user()->id]
        );

        return response()->json([
            'success' => true,
            'message' => $validated['action'] === 'accept' ? 'Buddy request accepted.' : 'Buddy request declined.',
            'data' => ['status' => $validated['action'] === 'accept' ? 'accepted' : 'declined'],
        ]);
    }

    private function userSummary(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'program' => $user->profile?->program?->name,
            'profile_photo_url' => $user->profile?->profile_photo_url,
            'average_rating' => $user->buddy_reviews_received_avg_rating === null
                ? null
                : round((float) $user->buddy_reviews_received_avg_rating, 1),
            'ratings_count' => $user->buddy_reviews_received_count,
        ];
    }
}
