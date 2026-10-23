<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BuddyRequest;
use App\Models\StudyGroup;
use App\Models\StudySession;
use App\Services\ActivityNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudySessionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $sessions = StudySession::query()
            ->where(fn ($query) => $query
                ->where('host_user_id', $request->user()->id)
                ->orWhere('participant_user_id', $request->user()->id)
                ->orWhereHas('group.members', fn ($members) => $members->where('users.id', $request->user()->id)))
            ->whereDoesntHave('hiddenByUsers', fn ($users) => $users->whereKey($request->user()->id))
            ->with([
                'host:id,name',
                'host.profile:id,user_id,profile_photo_url',
                'participant:id,name',
                'participant.profile:id,user_id,profile_photo_url',
                'group:id,name,avatar_icon,avatar_color',
            ])
            ->orderBy('starts_at')
            ->get()
            ->map(fn (StudySession $session) => $this->serialize($session, $request->user()->id));

        return response()->json([
            'success' => true,
            'message' => 'Study sessions retrieved successfully.',
            'data' => ['sessions' => $sessions],
        ]);
    }

    public function store(Request $request, ActivityNotificationService $notifications): JsonResponse
    {
        $validated = $request->validate([
            'participant_user_id' => ['required_without:study_group_id', 'nullable', 'integer', 'exists:users,id'],
            'study_group_id' => ['required_without:participant_user_id', 'nullable', 'integer', 'exists:study_groups,id'],
            'topic' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'date', 'after:now'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'location' => ['required', 'string', 'max:255'],
        ]);
        if (isset($validated['participant_user_id']) && isset($validated['study_group_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'Choose either one paired buddy or one joined group for the session.',
            ], 422);
        }

        $participantId = isset($validated['participant_user_id'])
            ? (int) $validated['participant_user_id']
            : null;
        $groupId = isset($validated['study_group_id']) ? (int) $validated['study_group_id'] : null;

        if ($participantId !== null && $participantId === $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'A study session needs another study buddy.',
            ], 422);
        }

        if ($participantId !== null) {
            $isBuddy = BuddyRequest::query()
                ->where('status', 'accepted')
                ->where(fn ($query) => $query
                    ->where(fn ($pair) => $pair->where('requester_id', $request->user()->id)->where('recipient_id', $participantId))
                    ->orWhere(fn ($pair) => $pair->where('requester_id', $participantId)->where('recipient_id', $request->user()->id)))
                ->exists();

            if (! $isBuddy) {
                return response()->json([
                    'success' => false,
                    'message' => 'You can schedule buddy sessions only with an accepted study buddy.',
                ], 403);
            }
        }

        if ($groupId !== null) {
            $isGroupMember = $request->user()->studyGroups()->whereKey($groupId)->exists();
            if (! $isGroupMember) {
                return response()->json([
                    'success' => false,
                    'message' => 'You can schedule group sessions only for a group you have joined.',
                ], 403);
            }
            StudyGroup::query()->findOrFail($groupId);
        }

        $session = StudySession::create([
            ...$validated,
            'host_user_id' => $request->user()->id,
            'participant_user_id' => $participantId,
            'study_group_id' => $groupId,
            'status' => 'pending',
        ])->load([
            'host:id,name',
            'host.profile:id,user_id,profile_photo_url',
            'participant:id,name',
            'participant.profile:id,user_id,profile_photo_url',
            'group:id,name,avatar_icon,avatar_color',
        ]);
        $recipientIds = $groupId !== null
            ? StudyGroup::query()->findOrFail($groupId)->members()->pluck('users.id')->all()
            : [$participantId];
        $notifications->sendToUsers(
            $recipientIds,
            "session:{$session->id}:invitation",
            'session_invitation',
            'Study session invitation',
            "{$request->user()->name} invited you to {$session->topic}.",
            ['session_id' => $session->id],
            $request->user()->id
        );

        return response()->json([
            'success' => true,
            'message' => $groupId ? 'Study session invitation sent to the group.' : 'Study session invitation sent.',
            'data' => ['session' => $this->serialize($session, $request->user()->id)],
        ], 201);
    }

    public function update(Request $request, int $id, ActivityNotificationService $notifications): JsonResponse
    {
        $session = StudySession::query()
            ->where(fn ($query) => $query
                ->where('host_user_id', $request->user()->id)
                ->orWhere('participant_user_id', $request->user()->id)
                ->orWhereHas('group.members', fn ($members) => $members->where('users.id', $request->user()->id)))
            ->findOrFail($id);
        $isHost = $session->host_user_id === $request->user()->id;
        $isStatusChange = $request->exists('status')
            && ! $request->hasAny(['topic', 'starts_at', 'ends_at', 'location']);

        if ($isStatusChange) {
            $validated = $request->validate([
                'status' => ['required', 'in:confirmed,declined,cancelled,completed'],
            ]);
            $status = $validated['status'];
            $isPast = ($session->ends_at ?? $session->starts_at)->isPast();
            $allowed = $isHost
                ? $status === 'cancelled'
                    || ($status === 'completed' && $session->status === 'confirmed' && $isPast)
                : ($status === 'cancelled' && $session->status === 'confirmed' && ! $isPast)
                    || ($session->status === 'pending'
                        && ! $isPast
                        && in_array($status, ['confirmed', 'declined'], true));

            if (! $allowed) {
                return response()->json([
                    'success' => false,
                    'message' => 'You cannot apply that status to this session.',
                ], 403);
            }

            $session->update(['status' => $status]);
            if (in_array($status, ['confirmed', 'declined'], true)) {
                $notifications->send(
                    $session->host_user_id,
                    "session:{$session->id}:response:{$status}:".now()->format('Uu'),
                    $status === 'confirmed' ? 'session_accepted' : 'session_declined',
                    $status === 'confirmed' ? 'Study session accepted' : 'Study session declined',
                    $status === 'confirmed'
                        ? "{$request->user()->name} accepted the study session invitation."
                        : "{$request->user()->name} declined the study session invitation.",
                    ['session_id' => $session->id]
                );
            }
        } else {
            if (! $isHost) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only the session creator can edit session details.',
                ], 403);
            }

            $isReschedulingCompleted = $session->status === 'completed';
            $isReschedulingCancelled = $session->status === 'cancelled';
            if (
                ! in_array($session->status, ['pending', 'confirmed', 'completed', 'cancelled'], true)
                || (! $isReschedulingCompleted && ! $isReschedulingCancelled && $session->starts_at->isPast())
            ) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only upcoming sessions can be edited, or completed and cancelled sessions can be rescheduled.',
                ], 422);
            }

            $validated = $request->validate([
                'topic' => ['required', 'string', 'max:255'],
                'starts_at' => ['required', 'date', 'after:now'],
                'ends_at' => ['required', 'date', 'after:starts_at'],
                'location' => ['required', 'string', 'max:255'],
            ]);

            if ($isReschedulingCompleted) {
                $session->reviews()->delete();
            }
            if ($isReschedulingCompleted || $isReschedulingCancelled) {
                $validated['status'] = 'pending';
            }

            $session->update($validated);
            if ($isReschedulingCompleted || $isReschedulingCancelled) {
                $session->hiddenByUsers()->detach();
                $recipientIds = $session->study_group_id !== null
                    ? $session->group()->firstOrFail()->members()->pluck('users.id')->all()
                    : [$session->participant_user_id];
                $notifications->sendToUsers(
                    $recipientIds,
                    "session:{$session->id}:rescheduled:".now()->format('Uu'),
                    'session_invitation',
                    'Study session invitation',
                    "{$request->user()->name} rescheduled {$session->topic}. Please review and respond to the invitation.",
                    ['session_id' => $session->id],
                    $request->user()->id
                );
            }
        }
        $session->load([
            'host:id,name',
            'host.profile:id,user_id,profile_photo_url',
            'participant:id,name',
            'participant.profile:id,user_id,profile_photo_url',
            'group:id,name,avatar_icon,avatar_color',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Study session updated.',
            'data' => ['session' => $this->serialize($session, $request->user()->id)],
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $session = StudySession::query()
            ->where(fn ($query) => $query
                ->where('host_user_id', $request->user()->id)
                ->orWhere('participant_user_id', $request->user()->id)
                ->orWhereHas('group.members', fn ($members) => $members->where('users.id', $request->user()->id)))
            ->whereIn('status', ['completed', 'cancelled'])
            ->findOrFail($id);

        if ($session->host_user_id === $request->user()->id) {
            $session->delete();
            $message = 'Study session deleted.';
        } else {
            $session->hiddenByUsers()->syncWithoutDetaching([$request->user()->id]);
            $message = 'Study session removed from your list.';
        }

        return response()->json([
            'success' => true,
            'message' => $message,
        ]);
    }

    private function serialize(StudySession $session, int $viewerId): array
    {
        $otherParticipant = $session->host_user_id === $viewerId
            ? $session->participant
            : $session->host;
        $targetName = $session->group?->name ?? $otherParticipant?->name;

        return [
            'id' => $session->id,
            'host_user_id' => $session->host_user_id,
            'participant_user_id' => $session->participant_user_id,
            'study_group_id' => $session->study_group_id,
            'target_type' => $session->study_group_id ? 'group' : 'buddy',
            'other_user_name' => $targetName,
            'other_user_profile_photo_url' => $session->study_group_id ? null : $otherParticipant?->profile?->profile_photo_url,
            'group_avatar_icon' => $session->group?->avatar_icon,
            'group_avatar_color' => $session->group?->avatar_color,
            'topic' => $session->topic,
            'starts_at' => $session->starts_at->toIso8601String(),
            'ends_at' => $session->ends_at?->toIso8601String(),
            'location' => $session->location,
            'status' => $session->status,
            'is_past' => in_array($session->status, ['pending', 'confirmed'], true)
                && ($session->ends_at ?? $session->starts_at)->isPast(),
        ];
    }
}
