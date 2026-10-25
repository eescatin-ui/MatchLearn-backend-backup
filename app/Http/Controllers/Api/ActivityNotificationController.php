<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityNotification;
use App\Models\StudySession;
use App\Services\ActivityNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ActivityNotificationController extends Controller
{
    public function index(Request $request, ActivityNotificationService $notifications): JsonResponse
    {
        $this->createSessionReminders($request, $notifications);

        $items = ActivityNotification::query()
            ->where('user_id', $request->user()->id)
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (ActivityNotification $notification): array => [
                'id' => $notification->id,
                'type' => $notification->type,
                'title' => $notification->title,
                'message' => $notification->message,
                'data' => $notification->data ?? [],
                'read_at' => $notification->read_at?->toIso8601String(),
                'created_at' => $notification->created_at->toIso8601String(),
            ]);

        return response()->json([
            'success' => true,
            'data' => [
                'notifications' => $items,
                'unread_count' => ActivityNotification::query()
                    ->where('user_id', $request->user()->id)
                    ->whereNull('read_at')
                    ->count(),
            ],
        ]);
    }

    public function markRead(Request $request, int $id): JsonResponse
    {
        $notification = ActivityNotification::query()
            ->where('user_id', $request->user()->id)
            ->findOrFail($id);
        $notification->update(['read_at' => now()]);

        return response()->json(['success' => true]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        ActivityNotification::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['success' => true]);
    }

    public function destroySelected(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['required', 'integer', 'distinct', 'min:1'],
        ]);

        ActivityNotification::query()
            ->where('user_id', $request->user()->id)
            ->whereIn('id', $validated['ids'])
            ->delete();

        return response()->json([
            'success' => true,
            'data' => [
                'unread_count' => ActivityNotification::query()
                    ->where('user_id', $request->user()->id)
                    ->whereNull('read_at')
                    ->count(),
            ],
        ]);
    }

    private function createSessionReminders(Request $request, ActivityNotificationService $notifications): void
    {
        $userId = $request->user()->id;
        $timezone = 'Asia/Manila';
        $today = Carbon::now($timezone)->startOfDay();
        $ranges = [
            ['day' => $today, 'type' => 'session_today', 'label' => 'today'],
            ['day' => $today->copy()->addDay(), 'type' => 'session_tomorrow', 'label' => 'tomorrow'],
        ];

        foreach ($ranges as $range) {
            $start = $range['day']->copy()->startOfDay()->utc();
            $end = $range['day']->copy()->endOfDay()->utc();
            $sessions = StudySession::query()
                ->where('status', 'confirmed')
                ->whereBetween('starts_at', [$start, $end])
                ->where(fn ($query) => $query
                    ->where('host_user_id', $userId)
                    ->orWhere('participant_user_id', $userId)
                    ->orWhereHas('group.members', fn ($members) => $members->where('users.id', $userId)))
                ->with('group:id,name')
                ->get();

            foreach ($sessions as $session) {
                $name = $session->group?->name ?? $session->topic;
                $startTime = $session->starts_at->timezone($timezone)->format('g:i A');
                $notifications->send(
                    $userId,
                    "session:{$session->id}:reminder:{$range['label']}:".$range['day']->toDateString(),
                    $range['type'],
                    $range['label'] === 'today' ? 'Study session today' : 'Study session tomorrow',
                    "{$name} is scheduled for {$range['label']} at {$startTime}.",
                    ['session_id' => $session->id]
                );
            }
        }
    }
}
