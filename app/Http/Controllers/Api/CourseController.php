<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Department;
use App\Models\LearningStyle;
use App\Models\Strength;
use App\Models\Weakness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CourseController extends Controller
{
    public function catalog(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Academic catalog retrieved successfully.',
            'data' => [
                'departments' => Department::with('programs')->orderBy('name')->get(),
                'learning_styles' => LearningStyle::orderBy('name')->get(['id', 'name']),
                'strengths' => Strength::orderBy('name')->get(['id', 'name']),
                'weaknesses' => Weakness::orderBy('name')->get(['id', 'name']),
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $courses = Course::with('department')
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Courses retrieved successfully.',
            'data' => [
                'courses' => $courses->map(function ($course) {
                    return [
                        'id' => $course->id,
                        'code' => $course->code,
                        'name' => $course->name,
                        'department' => $course->department ? [
                            'id' => $course->department->id,
                            'name' => $course->department->name,
                        ] : null,
                    ];
                })->values(),
            ],
        ]);
    }
}
