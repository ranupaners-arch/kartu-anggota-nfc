<?php

use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CardController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\DivisionController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\ExcuseController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\InstanceController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\MemberAdminController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\MemberImportController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ScanController;
use App\Http\Controllers\Api\TermController;
use Illuminate\Support\Facades\Route;

Route::get('health', \App\Http\Controllers\Api\HealthController::class)->middleware('throttle:30,1');
Route::get('instance', InstanceController::class)->middleware('throttle:60,1');
Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('auth/forgot', [AuthController::class, 'forgot'])->middleware('throttle:5,1');
Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:5,1');

Route::middleware(['auth:sanctum', 'password.changed'])->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('me', [AuthController::class, 'me']);
    Route::put('me/password', [AuthController::class, 'changePassword'])->middleware('throttle:10,1');
    Route::get('me/qr', [MemberController::class, 'myQr']);
    Route::get('me/card', [MeController::class, 'card']);
    Route::post('me/card/init', [MeController::class, 'selfCardInit'])->middleware('throttle:10,1');
    Route::post('me/card/{card}/assign', [MeController::class, 'selfCardAssign'])->middleware('throttle:10,1');
    Route::post('me/card/replacement', [MeController::class, 'cardReplacement'])->middleware('throttle:5,1');
    Route::get('me/profile-form', [MeController::class, 'profileForm']);
    Route::post('me/profile', [MeController::class, 'submitProfile'])->middleware('throttle:5,1');
    Route::get('me/photo', [MeController::class, 'photo'])->name('api.me.photo');
    Route::get('me/events', [MeController::class, 'events']);
    Route::get('me/history', [MeController::class, 'history']);
    Route::get('me/announcements', [MeController::class, 'announcements']);
    Route::get('me/calendar', [\App\Http\Controllers\CalendarFeedController::class, 'url']);
    Route::post('me/calendar/reset', [\App\Http\Controllers\CalendarFeedController::class, 'reset'])->middleware('throttle:5,1');
    Route::get('me/report-card', [MeController::class, 'reportCard'])->middleware('throttle:10,1');
    Route::get('members/{member}/summary', [MemberController::class, 'summary']);
    Route::post('excuses', [ExcuseController::class, 'store']);
    Route::get('members/{member}/photo', [MemberAdminController::class, 'photo'])->name('api.members.photo');
    Route::get('exports/{export}', [ExportController::class, 'show']);
    Route::get('exports/{export}/download', [ExportController::class, 'download']);

    // Petugas, Admin Divisi, Super Admin
    Route::middleware('role:super_admin,division_admin,scanner')->group(function () {
        Route::get('events', [EventController::class, 'index']);
        Route::get('events/{event}/roster', [EventController::class, 'roster']);
        Route::middleware('throttle:120,1')->group(function () {
            Route::post('events/{event}/scan', [ScanController::class, 'scan']);
            Route::post('events/{event}/scan/batch', [ScanController::class, 'batch']);
        });
    });

    // Admin Divisi + Super Admin
    Route::middleware('role:super_admin,division_admin')->group(function () {
        Route::post('events', [EventController::class, 'store']);
        Route::patch('events/{event}', [EventController::class, 'update']);
        Route::patch('attendances/{attendance}', [AttendanceController::class, 'update']);
        Route::patch('excuses/{excuse}', [ExcuseController::class, 'review']);
        Route::get('terms', [TermController::class, 'index']);
        Route::get('divisions', [DivisionController::class, 'index']);
        Route::get('reports/events/{event}', [ReportController::class, 'event']);
        Route::get('reports/terms/{term}', [ReportController::class, 'term']);
        Route::post('exports', [ExportController::class, 'store']);
    });

    // Hanya Super Admin
    Route::middleware('role:super_admin')->group(function () {
        Route::get('devices', [DeviceController::class, 'index']);
        Route::post('devices/{device}/revoke', [DeviceController::class, 'revoke']);
        Route::post('cards/init', [CardController::class, 'init']);
        Route::get('members', [MemberAdminController::class, 'index']);
        Route::post('members', [MemberAdminController::class, 'store']);
        Route::post('members/import/preview', [MemberImportController::class, 'preview']);
        Route::post('members/import/commit', [MemberImportController::class, 'commit']);
        Route::get('members/{member}', [MemberAdminController::class, 'show']);
        Route::patch('members/{member}', [MemberAdminController::class, 'update']);
        Route::delete('members/{member}', [MemberAdminController::class, 'destroy']);
        Route::post('members/{member}/photo', [MemberAdminController::class, 'uploadPhoto']);
        Route::post('terms', [TermController::class, 'store']);
        Route::patch('terms/{term}', [TermController::class, 'update']);
        Route::post('divisions', [DivisionController::class, 'store']);
        Route::patch('divisions/{division}', [DivisionController::class, 'update']);
        Route::post('cards/{card}/assign', [CardController::class, 'assign']);
        Route::post('cards/{card}/block', [CardController::class, 'block']);
    });
});
