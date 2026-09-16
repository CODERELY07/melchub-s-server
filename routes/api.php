<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\LoansController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/profile', [AuthController::class, 'profile']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/user', function (Request $request) {
        $user = $request->user();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'roles' => $user->getRoleNames(), // returns collection of role names
            'permissions' => $user->getAllPermissions()->pluck('name'), // collection of permission names
        ]);
    });
});

Route::middleware(['auth:sanctum', 'role:admin'])->group(function () {
    Route::apiResource('loans', LoansController::class);
});
