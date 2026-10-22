<?php

use App\Http\Controllers\Admin\AdminController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/login', [AdminController::class, 'showLogin'])->name('login');
    Route::post('/login', [AdminController::class, 'login'])->name('login.submit');

    Route::middleware(['auth', 'admin'])->group(function (): void {
        Route::post('/logout', [AdminController::class, 'logout'])->name('logout');
        Route::get('/', [AdminController::class, 'dashboard'])->name('dashboard');

        Route::prefix('api')->name('api.')->group(function (): void {
            Route::get('/analytics', [AdminController::class, 'analytics'])->name('analytics');
            Route::get('/users', [AdminController::class, 'users'])->name('users');
            Route::patch('/users/{userId}/status', [AdminController::class, 'updateUserStatus'])->name('users.status');
            Route::get('/groups', [AdminController::class, 'groups'])->name('groups');
            Route::put('/groups/{groupId}', [AdminController::class, 'updateGroup'])->name('groups.update');
            Route::delete('/groups/{groupId}', [AdminController::class, 'deleteGroup'])->name('groups.delete');
            Route::get('/study-sessions', [AdminController::class, 'studySessions'])->name('study-sessions');
            Route::put('/study-sessions/{sessionId}', [AdminController::class, 'updateStudySession'])->name('study-sessions.update');
            Route::delete('/study-sessions/{sessionId}', [AdminController::class, 'deleteStudySession'])->name('study-sessions.delete');
            Route::get('/reports', [AdminController::class, 'reports'])->name('reports');
            Route::patch('/reports/{reportId}', [AdminController::class, 'updateReport'])->name('reports.update');
            Route::get('/matching-weights', [AdminController::class, 'matchingWeights'])->name('matching-weights');
            Route::put('/matching-weights', [AdminController::class, 'updateMatchingWeights'])->name('matching-weights.update');
        });
    });
});
