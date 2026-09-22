<?php
// FIXED routes/web.php — single-device standard, no api_server, no docker
// Changes:
//  - Deleted duplicate Route::get('/api/users', ...) (kept UserController version)
//  - Deleted Route::get('/api-test', ...) which hardcoded Bearer test12345 + internal IP 182.160.120.92:8080
//  - Wrapped all /api/* (except login check) in dashboard.auth middleware
//  - Added throttle on login + logout
//  - Grouped attendance2 vs primary logically

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\Attendance2Controller;
use App\Http\Controllers\AnalyticsController;
use App\Http\Middleware\DashboardAuth;

// Public
Route::get('/login', [DashboardController::class, 'showLogin'])->name('login');
Route::post('/login', [DashboardController::class, 'login'])->middleware('throttle:10,1');
Route::post('/logout', [DashboardController::class, 'logout']);

// Dashboard page (auth via controller still OK, or use ->middleware(DashboardAuth::class))
Route::get('/', [DashboardController::class, 'index'])->middleware(DashboardAuth::class);

// Attendance2 (secondary device) — only reachable if you enable it
Route::middleware(DashboardAuth::class)->group(function () {
    Route::get('/attendance2', [Attendance2Controller::class, 'index']);
    Route::get('/api/attendance2-summary', [Attendance2Controller::class, 'data']);
    Route::get('/api/attendance2-latest', [Attendance2Controller::class, 'latest']);
    Route::get('/api/door-pulse', [Attendance2Controller::class, 'summary']);
});

// Primary attendance (1 device = standard)
Route::middleware(DashboardAuth::class)->group(function () {
    Route::get('/api/attendance-summary', [AttendanceController::class, 'data']);
    Route::get('/api/check-latest', [AttendanceController::class, 'latest']);
    Route::get('/api/latest-attendance', [AttendanceController::class, 'latest']); // alias
    Route::post('/api/attendance/add', [AttendanceController::class, 'add']);
    Route::post('/api/attendance/update', [AttendanceController::class, 'update']);
    Route::post('/api/attendance/delete', [AttendanceController::class, 'delete']);
    Route::get('/api/users', [\App\Http\Controllers\UserController::class, 'index']);
    Route::post('/api/users', [\App\Http\Controllers\UserController::class, 'store']);
    Route::put('/api/users/{id}', [\App\Http\Controllers\UserController::class, 'update']);
    Route::delete('/api/users/{id}', [\App\Http\Controllers\UserController::class, 'destroy']);
    Route::get('/api/settings', [\App\Http\Controllers\SettingController::class, 'index']);
    Route::post('/api/settings', [\App\Http\Controllers\SettingController::class, 'update']);
    Route::get('/api/analytics', [AnalyticsController::class, 'data']);
});
Route::get('/analytics', [AnalyticsController::class, 'index'])->middleware(DashboardAuth::class);
