<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StudyGroup;
use App\Models\StudyGroupJoinRequest;
use App\Models\StudySession;
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
                        ->with('user:id,name', 'user.profile:id,user_id,profile_photo_url')
                        ->latest()
                        ->get()
                        ->map(fn (StudyGroupJoinRequest $joinRequest): array => [
                            'id' => $joinRequest->id,
                            'user_id' => $joinRequest->user_id,
                            'user_name' => $joinRequest->user->name,
                            'profile_photo_url' => $joinRequest->user->profile?->profile_photo_url,
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
        $groups = StudyGroup::with('creator:id,name')
            ->whereDoesntHave('members', fn ($query) => $query->where('users.id', $request->user()->id))
            ->withCount('members')
            ->latest()
            ->get()
            ->map(function (StudyGroup $group) use ($request): array {
                $joinRequest = $group->joinRequests()
                    ->where('user_id', $request->user()->id)
                    ->where('status', 'pending')
                    ->first();

                return [
                    'id' => $group->id,
                    'name' => $group->name,
                    'course' => $group->course,
                    'avatar_icon' => $group->avatar_icon,
                    'avatar_color' => $group->avatar_color,
                    'schedule' => $group->schedule,
                    'description' => $group->description,
                    'members' => $group->members_count,
                    'created_by' => $group->created_by,
                    'request_status' => $joinRequest ? 'pending' : null,
                ];
            });

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
            'avatar_icon' => ['sometimes', 'required', 'string', 'in:book-outline,flask-outline,calculator-outline,code-slash-outline,bulb-outline,globe-outline'],
            'avatar_color' => ['sometimes', 'required', 'string', 'in:#1a5f7a,#15803d,#7c3aed,#c2410c,#be123c,#1d4ed8'],
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
        $group = $request->user()->studyGroups()
            ->with('creator:id,name', 'members:id,name', 'members.profile:id,user_id,profile_photo_url')
            ->with(['reviews' => fn ($query) => $query->with('reviewer:id,name')->latest()])
            ->withCount('members')
            ->findOrFail($id);

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
                    'schedule' => $group->schedule,
                    'description' => $group->description,
                    'members' => $group->members()->count(),
                    'role' => ucfirst($group->pivot->role),
                    'created_by' => $group->created_by,
                    'creator' => $group->creator ? [
                        'id' => $group->creator->id,
                        'name' => $group->creator->name,
                    ] : null,
                    'members_list' => $group->members->map(fn ($member): array => [
                        'id' => $member->id,
                        'name' => $member->name,
                        'profile_photo_url' => $member->profile?->profile_photo_url,
                        'role' => ucfirst($member->pivot->role),
                    ])->values(),
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
            'avatar_icon' => ['sometimes', 'required', 'string', 'in:book-outline,flask-outline,calculator-outline,code-slash-outline,bulb-outline,globe-outline'],
            'avatar_color' => ['sometimes', 'required', 'string', 'in:#1a5f7a,#15803d,#7c3aed,#c2410c,#be123c,#1d4ed8'],
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
}
