<?php

use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CardController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\ExcuseController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\ScanController;
use Illuminate\Support\Facades\Route;

Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('me', [AuthController::class, 'me']);
    Route::get('me/qr', [MemberController::class, 'myQr']);
    Route::get('members/{member}/summary', [MemberController::class, 'summary']);
    Route::post('excuses', [ExcuseController::class, 'store']);

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
    });

    // Hanya Super Admin
    Route::middleware('role:super_admin')->group(function () {
        Route::get('devices', [DeviceController::class, 'index']);
        Route::post('devices/{device}/revoke', [DeviceController::class, 'revoke']);
        Route::post('cards/init', [CardController::class, 'init']);
        Route::post('cards/{card}/assign', [CardController::class, 'assign']);
        Route::post('cards/{card}/block', [CardController::class, 'block']);
    });
});
