<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\CreateProfileRequest;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Http\Resources\ProfileResource;
use App\Models\Profile;
use App\Models\StudyCourse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class ProfileController extends Controller
{
    /**
     * Get authenticated user's profile.
     */
    public function show(Request $request): JsonResponse
    {
        $profile = $request->user()->profile;

        if (! $profile) {
            return response()->json([
                'success' => false,
                'message' => 'Profile not found.',
            ], 404);
        }

        $profile->load(['department', 'program', 'learningStyle', 'studyCourses']);

        return response()->json([
            'success' => true,
            'message' => 'Profile retrieved successfully.',
            'data' => [
                'profile' => ProfileResource::make($profile),
                'course_ids' => $request->user()->courses()->pluck('courses.id'),
                'strength_ids' => $request->user()->strengths()->pluck('strengths.id'),
                'weakness_ids' => $request->user()->weaknesses()->pluck('weaknesses.id'),
                'availabilities' => $request->user()->availabilities()->get(['day_of_week', 'start_time', 'end_time']),
            ],
        ]);
    }

    /**
     * Create user profile.
     */
    public function store(CreateProfileRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($user->profile) {
            return response()->json([
                'success' => false,
                'message' => 'Profile already exists.',
            ], 422);
        }

        $profile = DB::transaction(function () use ($request, $user) {
            if ($request->has('name')) {
                $user->update(['name' => $request->input('name')]);
            }
            $profile = $user->profile()->create($this->profileAttributes($request));
            $this->syncAssociations($request, $user, $profile);

            return $profile;
        });

        return response()->json([
            'success' => true,
            'message' => 'Profile created successfully.',
            'data' => [
                'profile' => ProfileResource::make($profile->load(['department', 'program', 'learningStyle', 'studyCourses'])),
                'course_ids' => $user->courses()->pluck('courses.id'),
                'strength_ids' => $user->strengths()->pluck('strengths.id'),
                'weakness_ids' => $user->weaknesses()->pluck('weaknesses.id'),
                'availabilities' => $user->availabilities()->get(['day_of_week', 'start_time', 'end_time']),
            ],
        ], 201);
    }

    /**
     * Update user profile.
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $profile = $user->profile()->first();

        if (! $profile) {
            return response()->json([
                'success' => false,
                'message' => 'Profile not found.',
            ], 404);
        }

        DB::transaction(function () use ($request, $user, $profile): void {
            if ($request->has('name')) {
                $user->update(['name' => $request->input('name')]);
            }

            $profile->update($this->profileAttributes($request));
            $this->syncAssociations($request, $user, $profile);
        });

        $profile->load(['department', 'program', 'learningStyle', 'studyCourses']);

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully.',
            'data' => [
                'profile' => ProfileResource::make($profile),
                'course_ids' => $user->courses()->pluck('courses.id'),
                'strength_ids' => $user->strengths()->pluck('strengths.id'),
                'weakness_ids' => $user->weaknesses()->pluck('weaknesses.id'),
                'availabilities' => $user->availabilities()->get(['day_of_week', 'start_time', 'end_time']),
            ],
        ]);
    }

    public function showPhoto(int $userId): Response
    {
        $profile = Profile::query()
            ->where('user_id', $userId)
            ->first(['profile_photo_data', 'profile_photo_mime_type']);

        if (! $profile?->profile_photo_data || ! $profile->profile_photo_mime_type) {
            abort(404, 'Profile photo not found.');
        }

        $photoData = base64_decode($profile->profile_photo_data, true);
        if ($photoData === false) {
            abort(500, 'Stored profile photo is invalid.');
        }

        return response($photoData, 200, [
            'Content-Type' => $profile->profile_photo_mime_type,
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function uploadPhoto(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);
        $profile = $request->user()->profile()->first();
        if (! $profile) {
            return response()->json([
                'success' => false,
                'message' => 'Save your profile before uploading a profile photo.',
            ], 404);
        }

        $photoData = file_get_contents($validated['photo']->getRealPath());
        if ($photoData === false) {
            return response()->json([
                'success' => false,
                'message' => 'The profile photo could not be stored. Please try again.',
            ], 500);
        }

        $profile->update([
            'profile_photo_data' => base64_encode($photoData),
            'profile_photo_mime_type' => $validated['photo']->getMimeType(),
            'profile_photo_url' => route('api.v1.profiles.photo', ['userId' => $profile->user_id]),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Profile photo uploaded successfully.',
            'data' => ['profile_photo_url' => $profile->profile_photo_url],
        ]);
    }

    private function syncAssociations(Request $request, User $user, Profile $profile): void
    {
        if ($request->exists('study_courses')) {
            $profile->studyCourses()->delete();
            $profile->studyCourses()->createMany(array_map(
                fn (array $course): array => [
                    'course_code' => trim($course['course_code']),
                    'course_code_normalized' => StudyCourse::normalizeCode($course['course_code']),
                    'course_name' => trim($course['course_name']),
                ],
                $request->input('study_courses') ?? []
            ));
        }

        foreach ([
            'course_ids' => 'courses',
            'strength_ids' => 'strengths',
            'weakness_ids' => 'weaknesses',
        ] as $field => $relation) {
            if ($request->has($field)) {
                $user->{$relation}()->sync($request->input($field));
            }
        }

        if ($request->has('availabilities')) {
            $user->availabilities()->delete();
            $user->availabilities()->createMany($request->input('availabilities', []));
        }

        if ($request->exists('academic_strengths') && ! $request->exists('strength_ids')) {
            $user->strengths()->sync([]);
        }

        if ($request->exists('academic_needs') && ! $request->exists('weakness_ids')) {
            $user->weaknesses()->sync([]);
        }
    }

    private function profileAttributes(CreateProfileRequest|UpdateProfileRequest $request): array
    {
        $attributes = $request->safe()->only([
            'student_id',
            'gender',
            'marital_status',
            'phone_number',
            'address',
            'phone_number_public',
            'address_public',
            'institution',
            'department_id',
            'program_id',
            'year_level',
            'learning_style_id',
            'bio',
            'study_mode',
        ]);

        foreach (['academic_strengths', 'academic_needs'] as $field) {
            if ($request->exists($field)) {
                $attributes[$field] = $this->normalizeTags($request->input($field, []));
            }
        }

        return $attributes;
    }

    private function normalizeTags(?array $tags): array
    {
        $normalized = [];
        $seen = [];

        foreach ($tags ?? [] as $tag) {
            $tag = trim($tag);
            $key = mb_strtolower($tag);
            if ($tag !== '' && ! isset($seen[$key])) {
                $normalized[] = $tag;
                $seen[$key] = true;
            }
        }

        return $normalized;
    }
}
