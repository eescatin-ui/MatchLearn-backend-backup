<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MatchResource;
use App\Services\MatchingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MatchingController extends Controller
{
    public function __construct(protected MatchingService $matchingService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $matches = $this->matchingService->getMatchesForUser($request->user());

        return response()->json([
            'success' => true,
            'message' => 'Matches retrieved successfully.',
            'data' => [
                'matches' => MatchResource::collection($matches),
            ],
        ]);
    }

    public function refresh(Request $request): JsonResponse
    {
        $matches = $this->matchingService->getMatchesForUser($request->user());

        return response()->json([
            'success' => true,
            'message' => 'Matches refreshed successfully.',
            'data' => [
                'matches' => MatchResource::collection($matches),
            ],
        ]);
    }
}
