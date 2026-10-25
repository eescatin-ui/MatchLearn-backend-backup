<?php

namespace App\Services;

use App\Models\StudyMatch;
use App\Models\BuddyRequest;
use App\Models\Course;
use App\Models\MatchingSetting;
use App\Models\StudyCourse;
use App\Models\User;
use App\Models\UserBlock;
use Illuminate\Support\Collection;

class MatchingService
{
    public function getMatchesForUser(User $user): Collection
    {
        $user->load(['profile.department', 'profile.program', 'profile.learningStyle', 'profile.studyCourses', 'courses', 'availabilities', 'strengths', 'weaknesses']);

        if (!$user->profile) {
            return collect();
        }

        $weights = MatchingSetting::query()
            ->where('key', 'default')
            ->first()?->weights ?? MatchingSetting::DEFAULT_WEIGHTS;

        $potentialMatches = User::query()
            ->whereKeyNot($user->id)
            ->where('is_suspended', false)
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('user_blocks')
                ->whereColumn('user_blocks.blocked_user_id', 'users.id')
                ->where('user_blocks.blocker_user_id', $user->id))
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('user_blocks')
                ->whereColumn('user_blocks.blocker_user_id', 'users.id')
                ->where('user_blocks.blocked_user_id', $user->id))
            ->with(['profile.department', 'profile.program', 'profile.learningStyle', 'profile.studyCourses', 'courses', 'availabilities', 'strengths', 'weaknesses'])
            ->get();

        $acceptedBuddyIds = BuddyRequest::query()
            ->where('status', 'accepted')
            ->where(function ($query) use ($user): void {
                $query->where('requester_id', $user->id)
                    ->orWhere('recipient_id', $user->id);
            })
            ->get(['requester_id', 'recipient_id'])
            ->map(fn (BuddyRequest $request): int => $request->requester_id === $user->id
                ? $request->recipient_id
                : $request->requester_id)
            ->all();

        $matches = collect();

        foreach ($potentialMatches as $candidate) {
            if (in_array($candidate->id, $acceptedBuddyIds, true)) {
                StudyMatch::query()
                    ->where('user_id', $user->id)
                    ->where('matched_user_id', $candidate->id)
                    ->delete();
                continue;
            }

            if (!$candidate->profile) {
                continue;
            }

            [$courseScore, $sharedCourses] = $this->scoreStudyCourseCompatibility($user, $candidate);
            $programScore = $this->scoreProgramCompatibility($user, $candidate);
            $academicScore = $this->scoreAcademicComplementarity($user, $candidate);
            $learningStyleScore = $this->scoreLearningStyleCompatibility($user, $candidate);
            $scheduleScore = $this->scoreScheduleCompatibility($user, $candidate);
            $yearScore = $this->scoreYearLevelProximity($user, $candidate);
            $studyModeScore = $this->scoreStudyModeCompatibility($user, $candidate);

            $compatibilityScore = min(100, max(0,
                $this->applyWeight($courseScore, 25, $weights['courses'])
                + $this->applyWeight($academicScore, 25, $weights['academic_complementarity'])
                + $this->applyWeight($programScore, 20, $weights['program'])
                + $this->applyWeight($learningStyleScore, 10, $weights['learning_style'])
                + $this->applyWeight($scheduleScore, 10, $weights['schedule'])
                + $this->applyWeight($yearScore, 5, $weights['year_level'])
                + $this->applyWeight($studyModeScore, 5, $weights['study_mode'])
            ));
            if ($compatibilityScore === 0) {
                StudyMatch::query()
                    ->where('user_id', $user->id)
                    ->where('matched_user_id', $candidate->id)
                    ->delete();
                continue;
            }

            $reasons = [];
            if ($sharedCourses->isNotEmpty()) {
                $courseLabels = $sharedCourses->map(fn (array $course) => $course['label']);
                $reasons[] = 'You are studying the same course code: ' . $courseLabels->implode(', ') . '.';
            }

            if ($programScore >= 10) {
                $reasons[] = 'You are in the same program or department.';
            }

            if ($academicScore >= 12) {
                $reasons[] = 'Your strengths can help with each other\'s academic needs.';
            }

            if ($learningStyleScore >= 7) {
                $reasons[] = 'Your study preferences are compatible.';
            }

            if ($scheduleScore >= 5) {
                $reasons[] = 'Your study schedules overlap.';
            }

            if ($yearScore >= 4) {
                $reasons[] = 'You are in a nearby year level.';
            }

            if ($studyModeScore > 0) {
                $reasons[] = 'You have compatible study modes.';
            }

            $match = StudyMatch::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'matched_user_id' => $candidate->id,
                ],
                [
                    'compatibility_score' => $compatibilityScore,
                    'shared_course_count' => $sharedCourses->count(),
                    'reasons' => $reasons,
                ]
            );

            $match->load([
                'matchedUser' => fn ($query) => $query
                    ->withAvg('buddyReviewsReceived', 'rating')
                    ->withCount('buddyReviewsReceived'),
                'matchedUser.profile.department',
                'matchedUser.profile.program',
                'matchedUser.profile.learningStyle',
                'matchedUser.profile.studyCourses',
                'matchedUser.courses',
                'matchedUser.strengths',
                'matchedUser.weaknesses',
            ]);
            $matches->push($match);
        }

        return $matches->sortByDesc('compatibility_score')->values();
    }

    private function scoreScheduleCompatibility(User $user, User $candidate): int
    {
        $userAvailability = $user->availabilities;
        $candidateAvailability = $candidate->availabilities;

        if ($userAvailability->isEmpty() || $candidateAvailability->isEmpty()) {
            return 0;
        }

        $overlapMinutes = 0;
        $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

        foreach ($days as $day) {
            $userSlots = $userAvailability->where('day_of_week', $day);
            $candidateSlots = $candidateAvailability->where('day_of_week', $day);

            foreach ($userSlots as $userSlot) {
                foreach ($candidateSlots as $candidateSlot) {
                    $overlap = $this->calculateOverlapMinutes(
                        $userSlot->start_time,
                        $userSlot->end_time,
                        $candidateSlot->start_time,
                        $candidateSlot->end_time
                    );

                    if ($overlap > 0) {
                        $overlapMinutes += $overlap;
                    }
                }
            }
        }

        $userAvailableMinutes = $userAvailability->sum(fn ($slot) =>
            $this->toMinutes($slot->end_time) - $this->toMinutes($slot->start_time)
        );
        $candidateAvailableMinutes = $candidateAvailability->sum(fn ($slot) =>
            $this->toMinutes($slot->end_time) - $this->toMinutes($slot->start_time)
        );
        $ratio = $overlapMinutes / max(1, min($userAvailableMinutes, $candidateAvailableMinutes));

        return min(10, (int) round($ratio * 10));
    }

    private function scoreAcademicComplementarity(User $user, User $candidate): int
    {
        $userStrengths = $this->normalizeTags(array_merge(
            $user->profile->academic_strengths ?? [],
            $user->strengths->pluck('name')->all()
        ));
        $candidateStrengths = $this->normalizeTags(array_merge(
            $candidate->profile->academic_strengths ?? [],
            $candidate->strengths->pluck('name')->all()
        ));
        $userNeeds = $this->normalizeTags(array_merge(
            $user->profile->academic_needs ?? [],
            $user->weaknesses->pluck('name')->all()
        ));
        $candidateNeeds = $this->normalizeTags(array_merge(
            $candidate->profile->academic_needs ?? [],
            $candidate->weaknesses->pluck('name')->all()
        ));

        $complementCount = count(array_intersect($userStrengths, $candidateNeeds))
            + count(array_intersect($candidateStrengths, $userNeeds));
        $sharedStrengthCount = count(array_intersect($userStrengths, $candidateStrengths));

        return min(25, ($complementCount * 13) + ($sharedStrengthCount > 0 ? 5 : 0));
    }

    private function scoreLearningStyleCompatibility(User $user, User $candidate): int
    {
        $userProfile = $user->profile;
        $candidateProfile = $candidate->profile;

        $userStyle = $userProfile && $userProfile->learningStyle ? $userProfile->learningStyle->name : null;
        $candidateStyle = $candidateProfile && $candidateProfile->learningStyle ? $candidateProfile->learningStyle->name : null;

        if (!$userStyle || !$candidateStyle) {
            return 0;
        }

        if (strtolower($userStyle) === strtolower($candidateStyle)) {
            return 10;
        }

        $pairings = [
            'visual' => ['auditory', 'reading/writing'],
            'auditory' => ['visual', 'kinesthetic'],
            'reading/writing' => ['visual', 'kinesthetic'],
            'kinesthetic' => ['auditory', 'reading/writing'],
        ];

        $normalizedUser = strtolower($userStyle);
        $normalizedCandidate = strtolower($candidateStyle);

        if (in_array($normalizedCandidate, $pairings[$normalizedUser] ?? [], true)) {
            return 7;
        }

        return 4;
    }

    private function scoreYearLevelProximity(User $user, User $candidate): int
    {
        $userYear = $user->profile?->year_level;
        $candidateYear = $candidate->profile?->year_level;
        if (!$userYear || !$candidateYear) {
            return 0;
        }

        $difference = abs($userYear - $candidateYear);

        if ($difference === 0) {
            return 5;
        }

        if ($difference === 1) {
            return 4;
        }

        if ($difference === 2) {
            return 2;
        }

        return 0;
    }

    private function scoreProgramCompatibility(User $user, User $candidate): int
    {
        $userProfile = $user->profile;
        $candidateProfile = $candidate->profile;
        if (!$userProfile || !$candidateProfile) {
            return 0;
        }

        if ($userProfile->program_id && $userProfile->program_id === $candidateProfile->program_id) {
            return 20;
        }

        if ($userProfile->department_id && $userProfile->department_id === $candidateProfile->department_id) {
            return 10;
        }

        return 0;
    }

    private function scoreStudyModeCompatibility(User $user, User $candidate): int
    {
        $userMode = $user->profile?->study_mode;
        $candidateMode = $candidate->profile?->study_mode;
        if (!$userMode || !$candidateMode) {
            return 0;
        }

        return $userMode === $candidateMode || $userMode === 'either' || $candidateMode === 'either' ? 5 : 0;
    }

    private function scoreStudyCourseCompatibility(User $user, User $candidate): array
    {
        $userCourses = $this->coursesForMatching($user);
        $candidateCourses = $this->coursesForMatching($candidate);
        $sharedCodes = $userCourses->keys()->intersect($candidateCourses->keys());
        $sharedCourses = $sharedCodes
            ->map(fn (string $code) => $userCourses->get($code))
            ->values();

        if ($sharedCourses->isEmpty()) {
            return [0, $sharedCourses];
        }

        $score = (int) round(($sharedCourses->count() / max($userCourses->count(), $candidateCourses->count())) * 25);

        return [max(1, $score), $sharedCourses];
    }

    private function coursesForMatching(User $user): Collection
    {
        $profileCourses = $user->profile->studyCourses->map(fn (StudyCourse $course) => [
            'code' => StudyCourse::normalizeCode($course->course_code),
            'label' => StudyCourse::extractCode($course->course_code).' - '.$course->course_name,
        ]);
        $catalogCourses = $user->courses->map(fn (Course $course) => [
            'code' => StudyCourse::normalizeCode($course->code),
            'label' => $course->code.' - '.$course->name,
        ]);

        return $profileCourses
            ->concat($catalogCourses)
            ->filter(fn (array $course) => $course['code'] !== '')
            ->unique('code')
            ->keyBy('code');
    }

    private function normalizeTags(array $tags): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (string $tag): string => mb_strtolower(trim($tag)),
            $tags
        ))));
    }

    private function calculateOverlapMinutes(string $startA, string $endA, string $startB, string $endB): int
    {
        $startAInMinutes = $this->toMinutes($startA);
        $endAInMinutes = $this->toMinutes($endA);
        $startBInMinutes = $this->toMinutes($startB);
        $endBInMinutes = $this->toMinutes($endB);

        $overlapStart = max($startAInMinutes, $startBInMinutes);
        $overlapEnd = min($endAInMinutes, $endBInMinutes);

        if ($overlapEnd <= $overlapStart) {
            return 0;
        }

        return $overlapEnd - $overlapStart;
    }

    private function toMinutes(string $time): int
    {
        $parts = explode(':', $time);
        $hours = (int) $parts[0];
        $minutes = (int) $parts[1];

        return ($hours * 60) + $minutes;
    }

    private function applyWeight(int $score, int $defaultMaximum, int $weight): int
    {
        return (int) round(($score / $defaultMaximum) * $weight);
    }
}
