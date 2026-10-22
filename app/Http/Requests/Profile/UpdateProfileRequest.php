<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'student_id' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
                Rule::unique('profiles', 'student_id')->ignore($this->user()->profile()->value('id')),
            ],
            'gender' => ['sometimes', 'nullable', 'string', 'in:Female,Male,Non-binary,Other,Prefer not to say'],
            'marital_status' => ['sometimes', 'nullable', 'string', 'in:Single,Married,Separated,Divorced,Widowed,Prefer not to say'],
            'phone_number' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^[0-9+().\\-\\s]+$/'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'phone_number_public' => ['sometimes', 'boolean'],
            'address_public' => ['sometimes', 'boolean'],
            'institution' => ['sometimes', 'string', 'in:SNSU,SSCT'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'program_id' => ['nullable', 'exists:programs,id'],
            'year_level' => ['nullable', 'integer', 'between:1,6'],
            'learning_style_id' => ['nullable', 'exists:learning_styles,id'],
            'bio' => ['nullable', 'string', 'max:500'],
            'academic_strengths' => ['sometimes', 'nullable', 'array', 'max:20'],
            'academic_strengths.*' => ['required', 'string', 'max:80'],
            'academic_needs' => ['sometimes', 'nullable', 'array', 'max:20'],
            'academic_needs.*' => ['required', 'string', 'max:80'],
            'study_mode' => ['sometimes', 'nullable', 'in:online,in_person,either'],
            'study_courses' => ['sometimes', 'nullable', 'array', 'max:20'],
            'study_courses.*.course_code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9]+(?:[ .\\/-][A-Za-z0-9]+)*$/'],
            'study_courses.*.course_name' => ['required', 'string', 'min:2', 'max:120'],
            'course_ids' => ['nullable', 'array'],
            'course_ids.*' => ['exists:courses,id'],
            'strength_ids' => ['nullable', 'array'],
            'strength_ids.*' => ['exists:strengths,id'],
            'weakness_ids' => ['nullable', 'array'],
            'weakness_ids.*' => ['exists:weaknesses,id'],
            'availabilities' => ['sometimes', 'array'],
            'availabilities.*.day_of_week' => ['required', 'in:monday,tuesday,wednesday,thursday,friday,saturday,sunday'],
            'availabilities.*.start_time' => ['required', 'date_format:H:i'],
            'availabilities.*.end_time' => ['required', 'date_format:H:i', 'after:availabilities.*.start_time'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $courses = $this->input('study_courses', []);
            if (! is_array($courses)) {
                return;
            }

            $seen = [];
            foreach ($courses as $index => $course) {
                if (! is_array($course) || ! isset($course['course_code']) || ! is_string($course['course_code'])) {
                    continue;
                }

                $normalized = preg_replace('/[^A-Za-z0-9]/', '', $course['course_code']);
                $key = strtoupper((string) $normalized);
                if ($key !== '' && isset($seen[$key])) {
                    $validator->errors()->add(
                        "study_courses.{$index}.course_code",
                        'Each course code can only be added once.'
                    );

                    continue;
                }

                $seen[$key] = true;
            }
        });
    }
}
