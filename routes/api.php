<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BorrowerAuthController;
use App\Http\Controllers\LoansController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);

Route::post('/borrower/login', [BorrowerAuthController::class, 'login']);

Route::middleware(['auth:sanctum', 'borrower'])->prefix('borrower')->group(function () {
    Route::post('/logout', [BorrowerAuthController::class, 'logout']);
    Route::get('/me', [BorrowerAuthController::class, 'me']);
    Route::put('/profile', [BorrowerAuthController::class, 'updateProfile']);
    Route::post('/change-password', [BorrowerAuthController::class, 'changePassword']);
    Route::get('/history', [BorrowerAuthController::class, 'history']);
});

Route::middleware(['auth:sanctum', 'staff'])->group(function () {
    Route::get('/profile', [AuthController::class, 'profile']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/user', function (Request $request) {
        $user = $request->user();

        return response()->json([
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'roles' => $user->getRoleNames(), // returns collection of role names
            'permissions' => $user->getAllPermissions()->pluck('name'), // collection of permission names
        ]);
    });
});

Route::middleware(['auth:sanctum', 'staff', 'role:admin'])->group(function () {
    Route::apiResource('loans', LoansController::class);
    Route::post('/loans/{loan}/payments', [LoansController::class, 'recordPayment']);
    Route::get('/loans/{loan}/history', [LoansController::class, 'history']);
});
