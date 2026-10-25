<?php

use App\Http\Controllers\Api\ActivityNotificationController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BuddyController;
use App\Http\Controllers\Api\CourseController;
use App\Http\Controllers\Api\MatchingController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\StudyGroupController;
use App\Http\Controllers\Api\StudyMessageController;
use App\Http\Controllers\Api\StudySessionController;
use App\Http\Controllers\Api\StudySessionReviewController;
use App\Http\Controllers\Api\UserReportController;
use App\Http\Controllers\Api\UserBlockController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('profiles/{userId}/photo', [ProfileController::class, 'showPhoto'])
        ->name('api.v1.profiles.photo');

    // Public auth routes
    Route::prefix('auth')->group(function () {
        Route::post('google', [AuthController::class, 'google']);

        // Protected auth routes
        Route::middleware('auth:sanctum')->group(function () {
            Route::get('me', [AuthController::class, 'me']);
            Route::post('logout', [AuthController::class, 'logout']);
            Route::post('logout-all', [AuthController::class, 'logoutAll']);
            Route::delete('account', [AuthController::class, 'deleteAccount']);
        });
    });

    // Protected routes
    Route::middleware('auth:sanctum')->group(function () {
        // Profiles
        Route::prefix('profiles')->group(function () {
            Route::get('/', [ProfileController::class, 'show']);
            Route::post('/', [ProfileController::class, 'store']);
            Route::put('/', [ProfileController::class, 'update']);
            Route::post('/photo', [ProfileController::class, 'uploadPhoto']);
        });

        // Courses and matching
        Route::get('courses', [CourseController::class, 'index']);
        Route::get('catalog', [CourseController::class, 'catalog']);

        Route::prefix('groups')->group(function () {
            Route::get('/', [StudyGroupController::class, 'index']);
            Route::get('/discover', [StudyGroupController::class, 'discover']);
            Route::post('/', [StudyGroupController::class, 'store']);
            Route::get('/{id}', [StudyGroupController::class, 'show']);
            Route::post('/{id}/reviews', [StudyGroupController::class, 'rate']);
            Route::post('/{id}/join', [StudyGroupController::class, 'join']);
            Route::patch('/{id}/join-requests/{requestId}', [StudyGroupController::class, 'respondToJoinRequest']);
            Route::delete('/{id}/leave', [StudyGroupController::class, 'leave']);
            Route::put('/{id}', [StudyGroupController::class, 'update']);
            Route::delete('/{id}', [StudyGroupController::class, 'destroy']);
        });

        Route::prefix('messages')->group(function () {
            Route::get('/', [StudyMessageController::class, 'index']);
            Route::post('/', [StudyMessageController::class, 'store']);
            Route::post('/direct-conversations', [StudyMessageController::class, 'createDirectConversation']);
            Route::get('/direct-conversations/{id}', [StudyMessageController::class, 'showDirect']);
            Route::post('/direct-conversations/{id}', [StudyMessageController::class, 'storeDirect']);
            Route::get('/{id}', [StudyMessageController::class, 'show']);
            Route::delete('/{id}', [StudyMessageController::class, 'destroy']);
        });

        Route::prefix('matches')->group(function () {
            Route::get('/', [MatchingController::class, 'index']);
            Route::post('/refresh', [MatchingController::class, 'refresh']);
        });
        Route::prefix('buddies')->group(function () {
            Route::get('/', [BuddyController::class, 'index']);
            Route::post('/requests', [BuddyController::class, 'store']);
            Route::patch('/requests/{id}', [BuddyController::class, 'respond']);
            Route::get('/{id}', [BuddyController::class, 'show']);
            Route::delete('/{id}', [BuddyController::class, 'destroy']);
        });
        Route::get('blocks', [UserBlockController::class, 'index']);
        Route::post('blocks', [UserBlockController::class, 'store']);
        Route::delete('blocks/{userId}', [UserBlockController::class, 'destroy']);

        Route::get('sessions', [StudySessionController::class, 'index']);
        Route::post('sessions', [StudySessionController::class, 'store']);
        Route::patch('sessions/{id}', [StudySessionController::class, 'update']);
        Route::delete('sessions/{id}', [StudySessionController::class, 'destroy']);
        Route::get('notifications', [ActivityNotificationController::class, 'index']);
        Route::patch('notifications/read-all', [ActivityNotificationController::class, 'markAllRead']);
        Route::patch('notifications/{id}/read', [ActivityNotificationController::class, 'markRead']);
        Route::get('reviews', [StudySessionReviewController::class, 'index']);
        Route::post('reviews', [StudySessionReviewController::class, 'store']);
        Route::post('reports/users', [UserReportController::class, 'store']);
        Route::get('reports/users/{reportedUserId}', [UserReportController::class, 'show']);
        Route::put('reports/users/{reportedUserId}', [UserReportController::class, 'update']);
        Route::delete('reports/users/{reportedUserId}', [UserReportController::class, 'destroy']);
    });
});
