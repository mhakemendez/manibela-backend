<?php

use App\Http\Controllers\Api\AuthController;
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