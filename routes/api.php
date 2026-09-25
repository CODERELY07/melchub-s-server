<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BorrowerAuthController;
use App\Http\Controllers\Api\BorrowerLoansController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\BorrowersController;
use App\Http\Controllers\LoanRequestController;
use App\Http\Controllers\LoansController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaymentProofController;
use App\Http\Controllers\RepaymentPlanController;
use App\Http\Controllers\SettingsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// throttle:6,1 — 6 attempts/minute per IP, to slow down credential
// stuffing/brute-force against the two login forms.
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1');

Route::post('/borrower/login', [BorrowerAuthController::class, 'login'])->middleware('throttle:6,1');

Route::middleware(['auth:sanctum', 'borrower'])->prefix('borrower')->group(function () {
    Route::post('/logout', [BorrowerAuthController::class, 'logout']);
    Route::get('/me', [BorrowerAuthController::class, 'me']);
    Route::put('/profile', [BorrowerAuthController::class, 'updateProfile']);
    Route::post('/change-password', [BorrowerAuthController::class, 'changePassword']);
    Route::post('/accept-terms', [BorrowerAuthController::class, 'acceptTerms']);
    Route::get('/loans', [BorrowerLoansController::class, 'index']);
    Route::get('/loans/{loan}', [BorrowerLoansController::class, 'show']);
    Route::get('/loans/{loan}/history', [BorrowerLoansController::class, 'history']);
    Route::post('/payment-proofs', [PaymentProofController::class, 'store']);
    Route::get('/payment-proofs', [PaymentProofController::class, 'mine']);
    Route::post('/loan-requests', [LoanRequestController::class, 'store']);
    Route::get('/loan-requests', [LoanRequestController::class, 'mine']);
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

// Readable by either staff or borrower tokens — both need to know where to
// send/expect GCash payments, and a borrower's own reminder SMS already
// quotes the late fee anyway, so there's nothing sensitive in it either.
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/settings/payment', [SettingsController::class, 'paymentInfo']);
    Route::get('/settings/loan-defaults', [SettingsController::class, 'loanDefaults']);
    Route::get('/repayment-plans', [RepaymentPlanController::class, 'index']);
});

Route::middleware(['auth:sanctum', 'staff', 'role:admin'])->group(function () {
    // Was public/unauthenticated — anyone on the internet could create a
    // staff account. Now only an existing admin can create another one.
    Route::post('/register', [AuthController::class, 'register']);

    Route::get('/borrowers', [BorrowersController::class, 'index']);

    Route::apiResource('loans', LoansController::class);
    Route::post('/loans/{loan}/payments', [LoansController::class, 'recordPayment']);
    Route::get('/loans/{loan}/history', [LoansController::class, 'history']);
    Route::post('/loans/{loan}/notify', [NotificationController::class, 'notify']);
    Route::post('/loans/notify-due', [NotificationController::class, 'notifyAllDue']);
    Route::post('/loans/{loan}/sms', [NotificationController::class, 'sendCustom']);
    Route::get('/loans/{loan}/sms-log', [NotificationController::class, 'smsLog']);

    Route::put('/settings/payment', [SettingsController::class, 'updatePaymentInfo']);
    Route::get('/settings/notifications', [SettingsController::class, 'notifications']);
    Route::put('/settings/notifications', [SettingsController::class, 'updateNotifications']);
    Route::put('/settings/loan-defaults', [SettingsController::class, 'updateLoanDefaults']);
    Route::get('/settings/lending-budget', [SettingsController::class, 'lendingBudget']);
    Route::put('/settings/lending-budget', [SettingsController::class, 'updateLendingBudget']);

    Route::get('/payment-proofs', [PaymentProofController::class, 'index']);
    Route::post('/payment-proofs/{paymentProof}/approve', [PaymentProofController::class, 'approve']);
    Route::post('/payment-proofs/{paymentProof}/reject', [PaymentProofController::class, 'reject']);

    Route::get('/loan-requests', [LoanRequestController::class, 'index']);
    Route::post('/loan-requests/{loanRequest}/accept', [LoanRequestController::class, 'accept']);
    Route::post('/loan-requests/{loanRequest}/decline', [LoanRequestController::class, 'decline']);

    Route::post('/repayment-plans', [RepaymentPlanController::class, 'store']);
    Route::put('/repayment-plans/{repaymentPlan}', [RepaymentPlanController::class, 'update']);
    Route::delete('/repayment-plans/{repaymentPlan}', [RepaymentPlanController::class, 'destroy']);

    Route::get('/analytics', [AnalyticsController::class, 'index']);
});
