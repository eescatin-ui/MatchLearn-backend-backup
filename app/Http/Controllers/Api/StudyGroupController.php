<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityNotification;
use App\Models\BuddyRequest;
use App\Models\StudyGroup;
use App\Models\StudyGroupJoinRequest;
use App\Models\StudySession;
use App\Models\UserBlock;
use App\Models\User;
use App\Services\ActivityNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class StudyGroupController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $groups = $request->user()->studyGroups()->with('creator:id,name')
            ->withCount('members')
            ->latest('study_groups.created_at')
            ->get()
            ->map(function (StudyGroup $group) use ($request) {
                $result = [
                    'id' => $group->id,
                    'name' => $group->name,
                    'course' => $group->course,
                    'avatar_icon' => $group->avatar_icon,
                    'avatar_color' => $group->avatar_color,
                    'schedule' => $group->schedule,
                    'description' => $group->description,
                    'study_style' => $group->study_style,
                    'preferred_study_time' => $group->preferred_study_time,
                    'study_mode' => $group->study_mode,
                    'members' => $group->members_count,
                    'role' => ucfirst($group->pivot->role),
                    'created_by' => $group->created_by,
                    'creator' => $group->creator ? [
                        'id' => $group->creator->id,
                        'name' => $group->creator->name,
                    ] : null,
                    'request_status' => null,
                    'creator' => $group->creator ? [
                        'id' => $group->creator->id,
                        'name' => $group->creator->name,
                    ] : null,
                ];
                if ($group->created_by === $request->user()->id) {
                    $result['join_requests'] = $group->joinRequests()
                        ->where('status', 'pending')
                        ->with([
                            'user' => fn ($query) => $query
                                ->select('id', 'name')
                                ->withAvg('buddyReviewsReceived', 'rating')
                                ->withCount('buddyReviewsReceived'),
                            'user.profile:id,user_id,profile_photo_url',
                        ])
                        ->latest()
                        ->get()
                        ->map(fn (StudyGroupJoinRequest $joinRequest): array => [
                            'id' => $joinRequest->id,
                            'user_id' => $joinRequest->user_id,
                            'user_name' => $joinRequest->user->name,
                            'profile_photo_url' => $joinRequest->user->profile?->profile_photo_url,
                            'average_rating' => $joinRequest->user->buddy_reviews_received_avg_rating === null
                                ? null
                                : round((float) $joinRequest->user->buddy_reviews_received_avg_rating, 1),
                            'ratings_count' => $joinRequest->user->buddy_reviews_received_count,
                        ])
                        ->values();
                }

                return $result;
            });

        return response()->json([
            'success' => true,
            'message' => 'Groups retrieved successfully.',
            'data' => [
                'groups' => $groups,
            ],
        ]);
    }

    public function discover(Request $request): JsonResponse
    {
        $user = $request->user()->loadMissing(['profile.learningStyle', 'availabilities']);
        $groups = StudyGroup::with('creator:id,name')
            ->withAvg('reviews', 'rating')
            ->withCount('reviews')
            ->whereDoesntHave('members', fn ($query) => $query->where('users.id', $request->user()->id))
            ->withCount('members')
            ->latest()
            ->get()
            ->map(function (StudyGroup $group) use ($request, $user): array {
                $joinRequest = $group->joinRequests()
                    ->where('user_id', $request->user()->id)
                    ->whereIn('status', ['pending', 'invited'])
                    ->first();

                return [
                    'id' => $group->id,
                    'name' => $group->name,
                    'course' => $group->course,
                    'avatar_icon' => $group->avatar_icon,
                    'avatar_color' => $group->avatar_color,
                    'schedule' => $group->schedule,
                    'description' => $group->description,
                    'study_style' => $group->study_style,
                    'preferred_study_time' => $group->preferred_study_time,
                    'study_mode' => $group->study_mode,
                    'compatibility_score' => $this->groupCompatibilityScore($group, $user),
                    'average_rating' => $group->reviews_avg_rating === null
                        ? null
                        : round((float) $group->reviews_avg_rating, 1),
                    'ratings_count' => $group->reviews_count,
                    'members' => $group->members_count,
                    'created_by' => $group->created_by,
                    'request_status' => $joinRequest?->status,
                    'request_id' => $joinRequest?->id,
                ];
            })
            ->sortByDesc('compatibility_score')
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'Discoverable groups retrieved successfully.',
            'data' => ['groups' => $groups],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'course' => ['required', 'string', 'max:255'],
            'avatar_icon' => ['sometimes', 'required', 'string', 'in:book-outline,flask-outline,calculator-outline,code-slash-outline,bulb-outline,globe-outline,construct-outline,laptop-outline,build-outline,school-outline,business-outline'],
            'avatar_color' => ['sometimes', 'required', 'string', 'in:#1a5f7a,#15803d,#7c3aed,#c2410c,#be123c,#1d4ed8'],
            'study_style' => ['sometimes', 'nullable', 'string', 'in:Visual,Auditory,Reading/Writing,Kinesthetic'],
            'preferred_study_time' => ['sometimes', 'nullable', 'string', 'in:Morning,Afternoon,Evening,Flexible'],
            'study_mode' => ['sometimes', 'nullable', 'string', 'in:online,in_person,either'],
            'schedule' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $group = DB::transaction(function () use ($request): StudyGroup {
            $group = StudyGroup::create([
                'created_by' => $request->user()->id,
                'name' => $request->input('name'),
                'course' => $request->input('course'),
                'avatar_icon' => $request->input('avatar_icon', 'book-outline'),
                'avatar_color' => $request->input('avatar_color', '#1a5f7a'),
                'study_style' => $request->input('study_style'),
                'preferred_study_time' => $request->input('preferred_study_time'),
                'study_mode' => $request->input('study_mode'),
                'schedule' => $request->input('schedule', 'Flexible'),
                'description' => $request->input('description'),
            ]);
            $group->members()->attach($request->user()->id, ['role' => 'admin']);

            return $group;
        });

        return response()->json([
            'success' => true,
            'message' => 'Group created successfully.',
            'data' => [
                'group' => [
                    'id' => $group->id,
                    'name' => $group->name,
                    'course' => $group->course,
                    'avatar_icon' => $group->avatar_icon,
                    'avatar_color' => $group->avatar_color,
                    'study_style' => $group->study_style,
                    'preferred_study_time' => $group->preferred_study_time,
                    'study_mode' => $group->study_mode,
                    'schedule' => $group->schedule,
                    'description' => $group->description,
                    'members' => 1,
                    'role' => 'Admin',
                    'created_by' => $group->created_by,
                ],
            ],
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $group = StudyGroup::query()
            ->with([
                'creator:id,name',
                'members' => fn ($query) => $query
                    ->select('id', 'name')
                    ->withAvg('buddyReviewsReceived', 'rating')
                    ->withCount('buddyReviewsReceived'),
                'members.profile:id,user_id,profile_photo_url',
            ])
            ->with(['reviews' => fn ($query) => $query->with('reviewer:id,name')->latest()])
            ->withCount('members')
            ->withCount(['sessions as total_sessions' => fn ($query) => $query
                ->whereIn('status', ['confirmed', 'completed'])])
            ->findOrFail($id);
        $userId = $request->user()->id;
        $membership = $group->members->firstWhere('id', $userId);
        $joinRequest = $group->joinRequests()
            ->where('user_id', $userId)
            ->whereIn('status', ['pending', 'invited'])
            ->first();

        return response()->json([
            'success' => true,
            'message' => 'Group retrieved successfully.',
            'data' => [
                'group' => [
                    'id' => $group->id,
                    'name' => $group->name,
                    'course' => $group->course,
                    'avatar_icon' => $group->avatar_icon,
                    'avatar_color' => $group->avatar_color,
                    'study_style' => $group->study_style,
                    'preferred_study_time' => $group->preferred_study_time,
                    'study_mode' => $group->study_mode,
                    'schedule' => $group->schedule,
                    'description' => $group->description,
                    'members' => $group->members()->count(),
                    'total_sessions' => $group->total_sessions,
                    'role' => $membership ? ucfirst($membership->pivot->role) : null,
                    'request_status' => $joinRequest?->status,
                    'request_id' => $joinRequest?->id,
                    'created_by' => $group->created_by,
                    'creator' => $group->creator ? [
                        'id' => $group->creator->id,
                        'name' => $group->creator->name,
                    ] : null,
                    'members_list' => $group->members->map(fn ($member): array => [
                        'id' => $member->id,
                        'name' => $member->name,
                        'profile_photo_url' => $member->profile?->profile_photo_url,
                        'average_rating' => $member->buddy_reviews_received_avg_rating === null
                            ? null
                            : round((float) $member->buddy_reviews_received_avg_rating, 1),
                        'ratings_count' => $member->buddy_reviews_received_count,
                        'role' => ucfirst($member->pivot->role),
                    ])->values(),
                    'pending_invite_user_ids' => $group->created_by === $request->user()->id
                        ? $group->joinRequests()->where('status', 'invited')->pluck('user_id')->values()
                        : [],
                    'average_rating' => $group->reviews->isEmpty()
                        ? null
                        : round($group->reviews->avg('rating'), 1),
                    'ratings_count' => $group->reviews->count(),
                    'my_rating' => $group->reviews->firstWhere('reviewer_user_id', $request->user()->id)?->rating,
                    'reviews' => $group->reviews->map(fn ($review): array => [
                        'id' => $review->id,
                        'rating' => $review->rating,
                        'comment' => $review->comment,
                        'reviewer_name' => $review->reviewer->name,
                        'created_at' => $review->created_at->toIso8601String(),
                    ])->values(),
                ],
            ],
        ]);
    }

    public function rate(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $group = $request->user()->studyGroups()->findOrFail($id);
        $hasCompletedSession = StudySession::query()
            ->where('study_group_id', $group->id)
            ->where('status', 'completed')
            ->exists();
        if (! $hasCompletedSession) {
            return response()->json([
                'success' => false,
                'message' => 'Complete at least one study session with this group before rating it.',
            ], 403);
        }
        $review = $group->reviews()->updateOrCreate(
            ['reviewer_user_id' => $request->user()->id],
            ['rating' => $validated['rating'], 'comment' => $validated['comment'] ?? null]
        );

        return response()->json([
            'success' => true,
            'message' => 'Group rating saved.',
            'data' => ['review_id' => $review->id],
        ]);
    }

    public function join(Request $request, int $id, ActivityNotificationService $notifications): JsonResponse
    {
        $group = StudyGroup::findOrFail($id);
        if ($group->members()->where('users.id', $request->user()->id)->exists()) {
            return response()->json([
                'success' => true,
                'message' => 'You are already a member of this group.',
                'data' => ['group_id' => $group->id, 'status' => 'member'],
            ]);
        }

        $joinRequest = StudyGroupJoinRequest::firstOrNew([
            'study_group_id' => $group->id,
            'user_id' => $request->user()->id,
        ]);
        if ($joinRequest->exists && $joinRequest->status === 'invited') {
            return response()->json([
                'success' => false,
                'message' => 'Respond to your group invitation before requesting to join.',
            ], 409);
        }
        if ($joinRequest->exists && $joinRequest->status === 'pending') {
            return response()->json([
                'success' => true,
                'message' => 'Your request is already awaiting admin approval.',
                'data' => ['group_id' => $group->id, 'status' => 'pending'],
            ]);
        }

        $joinRequest->status = 'pending';
        $joinRequest->save();
        $notifications->send(
            $group->created_by,
            "group-join:{$joinRequest->id}:received:".now()->format('Uu'),
            'group_join_request',
            'New group join request',
            "{$request->user()->name} requested to join {$group->name}.",
            ['group_id' => $group->id, 'request_id' => $joinRequest->id]
        );

        return response()->json([
            'success' => true,
            'message' => 'Your request to join the group was sent.',
            'data' => [
                'group_id' => $group->id,
                'request_id' => $joinRequest->id,
                'status' => 'pending',
            ],
        ], 201);
    }

    public function cancelJoinRequest(
        Request $request,
        int $id,
        ActivityNotificationService $notifications
    ): JsonResponse {
        $joinRequest = StudyGroupJoinRequest::query()
            ->where('study_group_id', $id)
            ->where('user_id', $request->user()->id)
            ->where('status', 'pending')
            ->firstOrFail();
        $group = $joinRequest->group;

        DB::transaction(function () use ($joinRequest, $group, $request, $notifications): void {
            $joinRequest->update(['status' => 'cancelled']);
            ActivityNotification::query()
                ->where('user_id', $group->created_by)
                ->where('type', 'group_join_request')
                ->where('data->request_id', $joinRequest->id)
                ->update(['read_at' => now()]);
            $notifications->send(
                $group->created_by,
                "group-join:{$joinRequest->id}:cancelled:".now()->format('Uu'),
                'group_join_cancelled',
                'Group join request cancelled',
                "{$request->user()->name} cancelled their request to join {$group->name}.",
                ['group_id' => $group->id, 'request_id' => $joinRequest->id]
            );
        });

        return response()->json([
            'success' => true,
            'message' => 'Your group join request was cancelled.',
            'data' => ['status' => 'cancelled'],
        ]);
    }

    public function inviteBuddy(Request $request, int $id, ActivityNotificationService $notifications): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);
        $group = StudyGroup::findOrFail($id);
        if ($group->created_by !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Only the group admin can invite a buddy.',
            ], 403);
        }

        $inviteeId = (int) $validated['user_id'];
        if ($inviteeId === $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'You cannot invite yourself.'], 422);
        }
        if (UserBlock::blocksEitherDirection($request->user()->id, $inviteeId)) {
            return response()->json(['success' => false, 'message' => 'This buddy is unavailable to invite.'], 422);
        }

        $areBuddies = BuddyRequest::query()
            ->where('status', 'accepted')
            ->where(fn ($query) => $query
                ->where(fn ($pair) => $pair->where('requester_id', $request->user()->id)->where('recipient_id', $inviteeId))
                ->orWhere(fn ($pair) => $pair->where('requester_id', $inviteeId)->where('recipient_id', $request->user()->id)))
            ->exists();
        if (! $areBuddies) {
            return response()->json(['success' => false, 'message' => 'You can only invite an accepted study buddy.'], 422);
        }
        if ($group->members()->where('users.id', $inviteeId)->exists()) {
            return response()->json(['success' => false, 'message' => 'This buddy is already a group member.'], 409);
        }

        $joinRequest = StudyGroupJoinRequest::firstOrNew([
            'study_group_id' => $group->id,
            'user_id' => $inviteeId,
        ]);
        if ($joinRequest->exists && $joinRequest->status === 'invited') {
            return response()->json([
                'success' => true,
                'message' => 'This buddy already has a pending invitation.',
                'data' => ['request_id' => $joinRequest->id, 'status' => 'invited'],
            ]);
        }
        if ($joinRequest->exists && $joinRequest->status === 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'This buddy already has a pending request to join the group.',
            ], 409);
        }

        $joinRequest->fill([
            'invited_by_user_id' => $request->user()->id,
            'status' => 'invited',
        ]);
        $joinRequest->save();
        $notifications->send(
            $inviteeId,
            "group-invite:{$joinRequest->id}:".now()->format('Uu'),
            'group_invitation',
            'Study group invitation',
            "{$request->user()->name} invited you to join {$group->name}.",
            ['group_id' => $group->id, 'request_id' => $joinRequest->id]
        );

        return response()->json([
            'success' => true,
            'message' => 'Group invitation sent.',
            'data' => ['request_id' => $joinRequest->id, 'status' => 'invited'],
        ], 201);
    }

    public function respondToInvitation(
        Request $request,
        int $id,
        int $requestId,
        ActivityNotificationService $notifications
    ): JsonResponse {
        $validated = $request->validate(['action' => ['required', 'in:accept,decline']]);
        $group = StudyGroup::findOrFail($id);
        $invitation = $group->joinRequests()
            ->whereKey($requestId)
            ->where('user_id', $request->user()->id)
            ->where('status', 'invited')
            ->first();
        if (! $invitation) {
            return response()->json([
                'success' => false,
                'message' => 'Pending group invitation not found.',
            ], 404);
        }

        $accepted = $validated['action'] === 'accept';
        $inviteeId = $request->user()->id;
        DB::transaction(function () use ($group, $invitation, $accepted, $inviteeId): void {
            if ($accepted) {
                $group->members()->syncWithoutDetaching([
                    $invitation->user_id => ['role' => 'member'],
                ]);
                $invitation->update(['status' => 'accepted']);
            } else {
                $invitation->update(['status' => 'declined']);
            }

            ActivityNotification::query()
                ->where('user_id', $inviteeId)
                ->where('type', 'group_invitation')
                ->where('data->request_id', $invitation->id)
                ->update([
                    'type' => $accepted ? 'group_invitation_accepted' : 'group_invitation_declined',
                    'title' => $accepted ? 'Group invitation accepted' : 'Group invitation declined',
                    'message' => $accepted
                        ? "You accepted the invitation to join {$group->name}."
                        : "You declined the invitation to join {$group->name}.",
                    'read_at' => now(),
                ]);
        });

        if ($invitation->invited_by_user_id !== null) {
            $notifications->send(
                $invitation->invited_by_user_id,
                "group-invite:{$invitation->id}:{$validated['action']}:".now()->format('Uu'),
                $accepted ? 'group_invitation_accepted' : 'group_invitation_declined',
                $accepted ? 'Group invitation accepted' : 'Group invitation declined',
                $accepted
                    ? "{$request->user()->name} accepted your invitation to {$group->name}."
                    : "{$request->user()->name} declined your invitation to {$group->name}.",
                ['group_id' => $group->id, 'user_id' => $request->user()->id]
            );
        }

        return response()->json([
            'success' => true,
            'message' => $validated['action'] === 'accept' ? 'Group invitation accepted.' : 'Group invitation declined.',
            'data' => ['status' => $validated['action'] === 'accept' ? 'accepted' : 'declined'],
        ]);
    }

    public function respondToJoinRequest(
        Request $request,
        int $id,
        int $requestId,
        ActivityNotificationService $notifications
    ): JsonResponse {
        $group = StudyGroup::findOrFail($id);
        if ($group->created_by !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Only the group admin can respond to join requests.',
            ], 403);
        }

        $validated = $request->validate(['action' => ['required', 'in:accept,decline']]);
        $joinRequest = $group->joinRequests()
            ->whereKey($requestId)
            ->where('status', 'pending')
            ->first();
        if (! $joinRequest) {
            return response()->json([
                'success' => false,
                'message' => 'Pending group join request not found.',
            ], 404);
        }

        DB::transaction(function () use ($group, $joinRequest, $validated): void {
            if ($validated['action'] === 'accept') {
                $group->members()->syncWithoutDetaching([
                    $joinRequest->user_id => ['role' => 'member'],
                ]);
                $joinRequest->update(['status' => 'accepted']);
            } else {
                $joinRequest->update(['status' => 'declined']);
            }
        });
        $accepted = $validated['action'] === 'accept';
        $notifications->send(
            $joinRequest->user_id,
            "group-join:{$joinRequest->id}:{$validated['action']}:".now()->format('Uu'),
            $accepted ? 'group_join_accepted' : 'group_join_declined',
            $accepted ? 'Group request accepted' : 'Group request declined',
            $accepted
                ? "Your request to join {$group->name} was accepted."
                : "Your request to join {$group->name} was declined.",
            ['group_id' => $group->id]
        );

        return response()->json([
            'success' => true,
            'message' => $validated['action'] === 'accept'
                ? 'Join request accepted.'
                : 'Join request declined.',
            'data' => ['status' => $validated['action'] === 'accept' ? 'accepted' : 'declined'],
        ]);
    }

    public function leave(Request $request, int $id, ActivityNotificationService $notifications): JsonResponse
    {
        $group = $request->user()->studyGroups()->findOrFail($id);
        $memberIds = $group->members()->pluck('users.id')->all();
        $departingUser = $request->user();

        if ($group->created_by === $request->user()->id) {
            $validated = $request->validate([
                'new_admin_user_id' => ['required', 'integer', 'exists:users,id'],
            ]);
            $newAdminId = (int) $validated['new_admin_user_id'];
            $isMember = $group->members()
                ->where('users.id', $newAdminId)
                ->wherePivot('role', 'member')
                ->exists();

            if (! $isMember || $newAdminId === $request->user()->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Choose another current group member as the new admin.',
                ], 422);
            }

            DB::transaction(function () use ($group, $newAdminId, $request): void {
                $group->members()->updateExistingPivot($newAdminId, ['role' => 'admin']);
                $group->update(['created_by' => $newAdminId]);
                $group->members()->detach($request->user()->id);
            });
            $notifications->sendToUsers(
                array_diff($memberIds, [$request->user()->id]),
                "group:{$group->id}:member-left:{$request->user()->id}:".now()->format('Uu'),
                'group_member_left',
                'A group member left',
                "{$departingUser->name} left {$group->name}. Admin rights were transferred.",
                ['group_id' => $group->id]
            );

            return response()->json([
                'success' => true,
                'message' => 'Admin role transferred and you left the group.',
            ]);
        }

        $group->members()->detach($request->user()->id);
        $notifications->sendToUsers(
            array_diff($memberIds, [$request->user()->id]),
            "group:{$group->id}:member-left:{$request->user()->id}:".now()->format('Uu'),
            'group_member_left',
            'A group member left',
            "{$departingUser->name} left {$group->name}.",
            ['group_id' => $group->id]
        );

        return response()->json([
            'success' => true,
            'message' => 'You left the group.',
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $group = $request->user()->studyGroups()->findOrFail($id);

        if ($group->created_by !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'You are not allowed to update this group.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => ['sometimes', 'string', 'max:255'],
            'course' => ['sometimes', 'string', 'max:255'],
            'schedule' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'avatar_icon' => ['sometimes', 'required', 'string', 'in:book-outline,flask-outline,calculator-outline,code-slash-outline,bulb-outline,globe-outline,construct-outline,laptop-outline,build-outline,school-outline,business-outline'],
            'avatar_color' => ['sometimes', 'required', 'string', 'in:#1a5f7a,#15803d,#7c3aed,#c2410c,#be123c,#1d4ed8'],
            'study_style' => ['sometimes', 'nullable', 'string', 'in:Visual,Auditory,Reading/Writing,Kinesthetic'],
            'preferred_study_time' => ['sometimes', 'nullable', 'string', 'in:Morning,Afternoon,Evening,Flexible'],
            'study_mode' => ['sometimes', 'nullable', 'string', 'in:online,in_person,either'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $group->fill($request->only([
            'name',
            'course',
            'schedule',
            'description',
            'avatar_icon',
            'avatar_color',
            'study_style',
            'preferred_study_time',
            'study_mode',
        ]));
        $group->save();

        return response()->json([
            'success' => true,
            'message' => 'Group updated successfully.',
            'data' => [
                'group' => $group,
            ],
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $group = StudyGroup::findOrFail($id);

        if ($group->created_by !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'You are not allowed to delete this group.',
            ], 403);
        }

        $group->delete();

        return response()->json([
            'success' => true,
            'message' => 'Group deleted successfully.',
        ]);
    }

    private function groupCompatibilityScore(StudyGroup $group, User $user): int
    {
        $scores = [];
        $profile = $user->profile;

        if ($group->study_style && $profile?->learningStyle?->name) {
            $scores[] = $group->study_style === $profile->learningStyle->name ? 100 : 0;
        }

        if ($group->study_mode && $profile?->study_mode) {
            $scores[] = $group->study_mode === $profile->study_mode
                || $group->study_mode === 'either'
                || $profile->study_mode === 'either'
                    ? 100
                    : 0;
        }

        if ($group->preferred_study_time && $user->availabilities->isNotEmpty()) {
            $timeRanges = [
                'Morning' => [480, 720],
                'Afternoon' => [720, 1020],
                'Evening' => [1020, 1260],
                'Flexible' => [480, 1260],
            ];
            [$groupStart, $groupEnd] = $timeRanges[$group->preferred_study_time];
            $hasTimeOverlap = $group->preferred_study_time === 'Flexible';

            foreach ($user->availabilities as $availability) {
                [$startHour, $startMinute] = array_map('intval', explode(':', $availability->start_time));
                [$endHour, $endMinute] = array_map('intval', explode(':', $availability->end_time));
                $availabilityStart = ($startHour * 60) + $startMinute;
                $availabilityEnd = ($endHour * 60) + $endMinute;
                if ($availabilityStart < $groupEnd && $availabilityEnd > $groupStart) {
                    $hasTimeOverlap = true;
                    break;
                }
            }
            $scores[] = $hasTimeOverlap ? 100 : 0;
        }

        return $scores === [] ? 0 : (int) round(array_sum($scores) / count($scores));
    }
}
