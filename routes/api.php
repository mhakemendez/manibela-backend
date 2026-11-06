<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\VehicleAvailabilityController;
use App\Http\Controllers\Api\VehicleController;
use App\Http\Controllers\Api\VehiclePhotoController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register'])
    ->middleware('throttle:5,1');

Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:5,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);
    Route::put('/profile/password', [ProfileController::class, 'updatePassword']);

    Route::middleware('role:customer')->group(function () {
        Route::get('/vehicles/nearby', [
            VehicleController::class,
            'nearby',
        ]);
    });

    Route::middleware('role:vehicle_owner')->group(function () {
        Route::apiResource('vehicles', VehicleController::class);


        Route::post('/vehicles/{vehicle}/photos', [
            VehiclePhotoController::class,
            'store',
        ]);

        Route::get('/vehicles/{vehicle}/photos', [
            VehiclePhotoController::class,
            'index',
        ]);

        Route::patch('/vehicles/{vehicle}/photos/{photo}/primary', [
            VehiclePhotoController::class,
            'setPrimary',
        ]);

        Route::delete('/vehicles/{vehicle}/photos/{photo}', [
            VehiclePhotoController::class,
            'destroy',
        ]);

        Route::get('/vehicles/{vehicle}/availability', [
            VehicleAvailabilityController::class,
            'index'
        ]);

        Route::post('/vehicles/{vehicle}/availability', [
            VehicleAvailabilityController::class,
            'store'
        ]);

        Route::put('/vehicles/{vehicle}/availability/{availability}', [
            VehicleAvailabilityController::class,
            'update'
        ]);

        Route::delete('/vehicles/{vehicle}/availability/{availability}', [
            VehicleAvailabilityController::class,
            'destroy'
        ]);

    });

    Route::prefix('admin')
        ->middleware('role:admin')
        ->group(function () {
            Route::get('/vehicles', [VehicleController::class, 'adminIndex']);

            Route::patch('/vehicles/{vehicle}/approve', [VehicleController::class, 'approve']);

            Route::patch('/vehicles/{vehicle}/reject', [VehicleController::class, 'reject']);
        });
});

if (app()->environment(['local', 'testing'])) {
    Route::middleware(['auth:sanctum', 'role:customer'])
        ->get('/rbac/customer', function (Request $request) {
            return response()->json([
                'message' => 'Customer access granted.',
                'role' => $request->user()->role,
            ]);
        });

    Route::middleware(['auth:sanctum', 'role:vehicle_owner'])
        ->get('/rbac/vehicle-owner', function (Request $request) {
            return response()->json([
                'message' => 'Vehicle owner access granted.',
                'role' => $request->user()->role,
            ]);
        });

    Route::middleware(['auth:sanctum', 'role:admin'])
        ->get('/rbac/admin', function (Request $request) {
            return response()->json([
                'message' => 'Admin access granted.',
                'role' => $request->user()->role,
            ]);
        });
}