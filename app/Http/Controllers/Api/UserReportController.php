<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BuddyRequest;
use App\Models\UserReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserReportController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateReport($request);
        $reporterId = $request->user()->id;
        $reportedUserId = (int) $validated['reported_user_id'];

        $this->authorizeBuddyReport($reporterId, $reportedUserId);
        $report = UserReport::query()->firstOrNew([
            'reporter_user_id' => $reporterId,
            'reported_user_id' => $reportedUserId,
        ]);
        $this->applyReportInput($report, $validated);
        $report->status = 'pending';
        $report->save();

        return response()->json([
            'success' => true,
            'message' => 'Your report has been submitted for review.',
            'data' => [
                'report_id' => $report->id,
                'report' => $this->reportData($report),
            ],
        ], 201);
    }

    public function show(Request $request, int $reportedUserId): JsonResponse
    {
        $report = UserReport::query()
            ->where('reporter_user_id', $request->user()->id)
            ->where('reported_user_id', $reportedUserId)
            ->first();

        return response()->json([
            'success' => true,
            'data' => ['report' => $report ? $this->reportData($report) : null],
        ]);
    }

    public function update(Request $request, int $reportedUserId): JsonResponse
    {
        $validated = $this->validateReport($request, false);
        $report = $this->ownedReport($request, $reportedUserId);
        $this->authorizeBuddyReport($request->user()->id, $reportedUserId);

        $this->applyReportInput($report, $validated);
        $report->status = 'pending';
        $report->save();

        return response()->json([
            'success' => true,
            'message' => 'Your report has been updated.',
            'data' => ['report' => $this->reportData($report)],
        ]);
    }

    public function destroy(Request $request, int $reportedUserId): JsonResponse
    {
        $report = $this->ownedReport($request, $reportedUserId);
        $report->delete();

        return response()->json([
            'success' => true,
            'message' => 'Your report has been withdrawn.',
        ]);
    }

    private function validateReport(Request $request, bool $includeReportedUserId = true): array
    {
        $rules = [
            'reason' => ['required_without:reasons', 'nullable', Rule::in([
                'harassment',
                'inappropriate_content',
                'fake_profile',
                'privacy_or_safety',
                'other',
            ])],
            'reasons' => ['required_without:reason', 'array', 'min:1'],
            'reasons.*' => ['required', 'distinct', Rule::in([
                'harassment',
                'inappropriate_content',
                'fake_profile',
                'privacy_or_safety',
                'other',
            ])],
            'details' => ['nullable', 'string', 'max:2000'],
        ];

        if ($includeReportedUserId) {
            $rules['reported_user_id'] = ['required', 'integer', 'exists:users,id', Rule::notIn([$request->user()->id])];
        }

        return $request->validate($rules);
    }

    private function applyReportInput(UserReport $report, array $validated): void
    {
        $reasons = $validated['reasons'] ?? [$validated['reason']];
        $report->reason = $reasons[0];
        $report->reasons = $reasons;
        $report->details = $validated['details'] ?? null;
    }

    private function authorizeBuddyReport(int $reporterId, int $reportedUserId): void
    {
        $areBuddies = BuddyRequest::query()
            ->where('status', 'accepted')
            ->where(fn ($query) => $query
                ->where(fn ($pair) => $pair->where('requester_id', $reporterId)->where('recipient_id', $reportedUserId))
                ->orWhere(fn ($pair) => $pair->where('requester_id', $reportedUserId)->where('recipient_id', $reporterId)))
            ->exists();

        abort_unless($areBuddies, 403);
    }

    private function ownedReport(Request $request, int $reportedUserId): UserReport
    {
        return UserReport::query()
            ->where('reporter_user_id', $request->user()->id)
            ->where('reported_user_id', $reportedUserId)
            ->firstOrFail();
    }

    private function reportData(UserReport $report): array
    {
        return [
            'id' => $report->id,
            'reasons' => $report->reasons ?? [$report->reason],
            'details' => $report->details,
        ];
    }
}
