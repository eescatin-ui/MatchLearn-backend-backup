<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'matched_user_id' => $this->matched_user_id,
            'compatibility_score' => $this->compatibility_score,
            'shared_course_count' => $this->shared_course_count,
            'reasons' => $this->reasons ?? [],
            'matched_user' => $this->whenLoaded('matchedUser', function () {
                return [
                    'id' => $this->matchedUser->id,
                    'name' => $this->matchedUser->name,
                    'average_rating' => $this->matchedUser->buddy_reviews_received_avg_rating === null
                        ? null
                        : round((float) $this->matchedUser->buddy_reviews_received_avg_rating, 1),
                    'ratings_count' => $this->matchedUser->buddy_reviews_received_count,
                    'profile' => $this->matchedUser->profile ? [
                        'id' => $this->matchedUser->profile->id,
                        'profile_photo_url' => $this->matchedUser->profile->profile_photo_url,
                        'institution' => $this->matchedUser->profile->institution,
                        'gender' => $this->matchedUser->profile->gender,
                        'marital_status' => $this->matchedUser->profile->marital_status,
                        'year_level' => $this->matchedUser->profile->year_level,
                        'learning_style' => $this->matchedUser->profile->learningStyle ? $this->matchedUser->profile->learningStyle->name : null,
                        'program' => $this->matchedUser->profile->program?->name,
                        'study_courses' => $this->matchedUser->profile->studyCourses->map(fn ($course) => [
                            'course_code' => $course->course_code,
                            'normalized_course_code' => $course->course_code_normalized,
                            'course_name' => $course->course_name,
                        ])->values(),
                        'academic_strengths' => array_values(array_unique(array_merge(
                            $this->matchedUser->profile->academic_strengths ?? [],
                            $this->matchedUser->strengths->pluck('name')->all()
                        ))),
                        'academic_needs' => array_values(array_unique(array_merge(
                            $this->matchedUser->profile->academic_needs ?? [],
                            $this->matchedUser->weaknesses->pluck('name')->all()
                        ))),
                        'study_mode' => $this->matchedUser->profile->study_mode,
                        'bio' => $this->matchedUser->profile->bio,
                        'phone_number' => $this->matchedUser->profile->phone_number_public
                            ? $this->matchedUser->profile->phone_number
                            : null,
                        'address' => $this->matchedUser->profile->address_public
                            ? $this->matchedUser->profile->address
                            : null,
                    ] : null,
                    'courses' => $this->matchedUser->courses->map(function ($course) {
                        return [
                            'id' => $course->id,
                            'code' => $course->code,
                            'name' => $course->name,
                        ];
                    })->values(),
                ];
            }),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
