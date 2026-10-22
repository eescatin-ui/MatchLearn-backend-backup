<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BuddyRequest;
use App\Models\MatchingSetting;
use App\Models\StudyMatch;
use App\Models\StudyGroup;
use App\Models\StudySession;
use App\Models\User;
use App\Models\UserReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminController extends Controller
{
    public function showLogin(): View|RedirectResponse
    {
        if (Auth::check()) {
            return Auth::user()->is_admin ? redirect()->route('admin.dashboard') : redirect('/');
        }

        return view('admin.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            return back()->withErrors(['email' => 'The provided credentials are invalid.'])->onlyInput('email');
        }

        $request->session()->regenerate();

        if (! $request->user()->is_admin) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return back()->withErrors(['email' => 'This account does not have administrator access.'])->onlyInput('email');
        }

        return redirect()->intended(route('admin.dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }

    public function dashboard(): View
    {
        return view('admin.dashboard', [
            'admin' => request()->user(),
        ]);
    }

    public function analytics(): JsonResponse
    {
        $totalRequests = BuddyRequest::query()->count();
        $acceptedRequests = BuddyRequest::query()->where('status', 'accepted')->count();

        return response()->json([
            'users' => User::query()->count(),
            'active_users' => User::query()->where('is_suspended', false)->count(),
            'suspended_users' => User::query()->where('is_suspended', true)->count(),
            'pending_reports' => UserReport::query()->where('status', 'pending')->count(),
            'completed_sessions' => StudySession::query()->where('status', 'completed')->count(),
            'match_records' => StudyMatch::query()->count(),
            'accepted_buddy_connections' => $acceptedRequests,
            'buddy_request_acceptance_rate' => $totalRequests === 0
                ? 0
                : round(($acceptedRequests / $totalRequests) * 100, 1),
        ]);
    }

    public function users(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['all', 'active', 'suspended'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = User::query()->with([
            'profile:id,user_id,program_id,profile_photo_url',
            'profile.program:id,name',
        ]);
        if (! empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(fn ($users) => $users
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%"));
        }
        if (($validated['status'] ?? 'all') === 'active') {
            $query->where('is_suspended', false);
        } elseif (($validated['status'] ?? 'all') === 'suspended') {
            $query->where('is_suspended', true);
        }

        $users = $query->orderByDesc('created_at')->paginate(15)->through(fn (User $user) => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'profile_photo_url' => $user->profile?->profile_photo_url,
            'program' => $user->profile?->program?->name,
            'is_suspended' => $user->is_suspended,
            'is_admin' => $user->is_admin,
            'created_at' => $user->created_at->toIso8601String(),
        ]);

        return response()->json($users);
    }

    public function updateUserStatus(Request $request, int $userId): JsonResponse
    {
        $validated = $request->validate(['is_suspended' => ['required', 'boolean']]);
        $user = User::query()->findOrFail($userId);

        abort_if($user->is_admin || $user->is($request->user()), 403, 'Administrator accounts cannot be suspended here.');

        $user->is_suspended = $validated['is_suspended'];
        $user->save();

        if ($user->is_suspended) {
            $user->tokens()->delete();
        }

        return response()->json([
            'message' => $user->is_suspended ? 'Account suspended.' : 'Account restored.',
            'user' => [
                'id' => $user->id,
                'is_suspended' => $user->is_suspended,
            ],
        ]);
    }

    public function groups(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = StudyGroup::query()
            ->with('creator:id,name,email')
            ->withCount('members');
        if (! empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(fn ($groups) => $groups
                ->where('name', 'like', "%{$search}%")
                ->orWhere('course', 'like', "%{$search}%")
                ->orWhereHas('creator', fn ($creator) => $creator
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")));
        }

        $groups = $query->orderByDesc('created_at')->paginate(15)->through(fn (StudyGroup $group) => [
            'id' => $group->id,
            'name' => $group->name,
            'course' => $group->course,
            'schedule' => $group->schedule,
            'description' => $group->description,
            'creator' => $group->creator?->name,
            'members_count' => $group->members_count,
            'created_at' => $group->created_at->toIso8601String(),
        ]);

        return response()->json($groups);
    }

    public function updateGroup(Request $request, int $groupId): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'course' => ['required', 'string', 'max:255'],
            'schedule' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);

        $group = StudyGroup::query()->findOrFail($groupId);
        $group->update($validated);

        return response()->json(['message' => 'Study group updated.']);
    }

    public function deleteGroup(int $groupId): JsonResponse
    {
        $group = StudyGroup::query()->findOrFail($groupId);
        $group->delete();

        return response()->json(['message' => 'Study group deleted.']);
    }

    public function studySessions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['all', 'pending', 'confirmed', 'declined', 'cancelled', 'completed'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = StudySession::query()
            ->with(['host:id,name', 'participant:id,name', 'group:id,name']);
        if (! empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(fn ($sessions) => $sessions
                ->where('topic', 'like', "%{$search}%")
                ->orWhere('location', 'like', "%{$search}%")
                ->orWhereHas('host', fn ($host) => $host
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%"))
                ->orWhereHas('participant', fn ($participant) => $participant
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%"))
                ->orWhereHas('group', fn ($group) => $group->where('name', 'like', "%{$search}%")));
        }
        if (($validated['status'] ?? 'all') !== 'all') {
            $query->where('status', $validated['status']);
        }

        $sessions = $query->orderByDesc('starts_at')->paginate(15)->through(fn (StudySession $session) => [
            'id' => $session->id,
            'topic' => $session->topic,
            'host' => $session->host?->name,
            'participant' => $session->participant?->name,
            'group' => $session->group?->name,
            'starts_at' => $session->starts_at->toIso8601String(),
            'ends_at' => $session->ends_at?->toIso8601String(),
            'location' => $session->location,
            'status' => $session->status,
        ]);

        return response()->json($sessions);
    }

    public function updateStudySession(Request $request, int $sessionId): JsonResponse
    {
        $validated = $request->validate([
            'topic' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'location' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::in(['pending', 'confirmed', 'declined', 'cancelled', 'completed'])],
        ]);

        $session = StudySession::query()->findOrFail($sessionId);
        $session->update($validated);

        return response()->json(['message' => 'Study session updated.']);
    }

    public function deleteStudySession(int $sessionId): JsonResponse
    {
        $session = StudySession::query()->findOrFail($sessionId);
        $session->delete();

        return response()->json(['message' => 'Study session deleted.']);
    }

    public function reports(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['all', 'pending', 'reviewed', 'dismissed'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = UserReport::query()
            ->with('reporter:id,name,email', 'reportedUser:id,name,email')
            ->orderByDesc('created_at');
        if (($validated['status'] ?? 'all') !== 'all') {
            $query->where('status', $validated['status']);
        }

        return response()->json($query->paginate(15));
    }

    public function updateReport(Request $request, int $reportId): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['reviewed', 'dismissed'])],
        ]);

        $report = UserReport::query()->findOrFail($reportId);
        $report->update(['status' => $validated['status']]);

        return response()->json(['message' => 'Report status updated.', 'status' => $report->status]);
    }

    public function matchingWeights(): JsonResponse
    {
        $setting = MatchingSetting::query()->firstOrCreate(
            ['key' => 'default'],
            ['weights' => MatchingSetting::DEFAULT_WEIGHTS]
        );

        return response()->json([
            'weights' => $setting->weights,
            'total' => array_sum($setting->weights),
        ]);
    }

    public function updateMatchingWeights(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'weights' => ['required', 'array'],
            'weights.courses' => ['required', 'integer', 'between:0,100'],
            'weights.academic_complementarity' => ['required', 'integer', 'between:0,100'],
            'weights.program' => ['required', 'integer', 'between:0,100'],
            'weights.learning_style' => ['required', 'integer', 'between:0,100'],
            'weights.schedule' => ['required', 'integer', 'between:0,100'],
            'weights.year_level' => ['required', 'integer', 'between:0,100'],
            'weights.study_mode' => ['required', 'integer', 'between:0,100'],
        ]);

        if (array_sum($validated['weights']) !== 100) {
            return response()->json(['message' => 'Matching weights must add up to 100.'], 422);
        }

        $setting = MatchingSetting::query()->updateOrCreate(
            ['key' => 'default'],
            ['weights' => $validated['weights']]
        );

        return response()->json([
            'message' => 'Matching weights saved. They will be applied as students’ matches refresh.',
            'weights' => $setting->weights,
        ]);
    }
}
