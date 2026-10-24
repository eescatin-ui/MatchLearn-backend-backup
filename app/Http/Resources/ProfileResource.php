<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'student_id' => $this->student_id,
            'gender' => $this->gender,
            'marital_status' => $this->marital_status,
            'phone_number' => $this->when($request->user()?->id === $this->user_id, $this->phone_number),
            'address' => $this->when($request->user()?->id === $this->user_id, $this->address),
            'phone_number_public' => $this->when($request->user()?->id === $this->user_id, $this->phone_number_public),
            'address_public' => $this->when($request->user()?->id === $this->user_id, $this->address_public),
            'institution' => $this->institution,
            'department_id' => $this->department_id,
            'program_id' => $this->program_id,
            'year_level' => $this->year_level,
            'learning_style_id' => $this->learning_style_id,
            'bio' => $this->bio,
            'profile_photo_url' => $this->profile_photo_url,
            'study_courses' => $this->whenLoaded('studyCourses', fn () => $this->studyCourses->map(fn ($course) => [
                'course_code' => $course->course_code,
                'normalized_course_code' => $course->course_code_normalized,
                'course_name' => $course->course_name,
            ])->values()),
            'academic_strengths' => $this->academic_strengths ?? [],
            'academic_needs' => $this->academic_needs ?? [],
            'study_mode' => $this->study_mode,
            'department' => $this->whenLoaded('department', function () {
                return $this->department ? [
                    'id' => $this->department->id,
                    'name' => $this->department->name,
                ] : null;
            }),
            'program' => $this->whenLoaded('program', function () {
                return $this->program ? [
                    'id' => $this->program->id,
                    'name' => $this->program->name,
                ] : null;
            }),
            'learning_style' => $this->whenLoaded('learningStyle', function () {
                return $this->learningStyle ? [
                    'id' => $this->learningStyle->id,
                    'name' => $this->learningStyle->name,
                ] : null;
            }),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
