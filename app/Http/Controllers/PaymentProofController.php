<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use App\Models\PaymentProof;
use App\Services\SmsGatewayService;
use App\Services\SupabaseStorageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class PaymentProofController extends Controller
{
    public function __construct(
        private SupabaseStorageService $storage,
        private SmsGatewayService $sms,
    ) {
    }

    /**
     * Borrower: submit a GCash screenshot as proof of payment.
     */
    public function store(Request $request)
    {
        $loan = $request->user();

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'screenshot' => 'required|image|max:10240',
        ]);

        try {
            $upload = $this->storage->upload(
                $validated['screenshot'],
                "{$loan->loan_number}-{$loan->username}-".now()->format('Ymd_His').'.'.$validated['screenshot']->extension()
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $proof = $loan->paymentProofs()->create([
            'amount' => $validated['amount'],
            'file_path' => $upload['id'],
            'file_url' => $upload['url'],
            'status' => 'pending',
        ]);

        return response()->json($proof, 201);
    }

    /**
     * Borrower: their own submitted proofs, most recent first.
     */
    public function mine(Request $request)
    {
        return response()->json(
            $request->user()->paymentProofs()->latest()->get()
        );
    }

    /**
     * Admin: every submitted proof (optionally filtered by status).
     */
    public function index(Request $request)
    {
        $query = PaymentProof::with(['loan:id,loan_number,name,phone', 'reviewer:id,name'])->latest();

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        return response()->json($query->get());
    }

    /**
     * Admin: approve a proof — records the payment on the loan and thanks
     * the borrower by SMS.
     */
    public function approve(Request $request, PaymentProof $paymentProof)
    {
        if ($paymentProof->status !== 'pending') {
            return response()->json(['message' => 'This proof has already been reviewed.'], 422);
        }

        $validated = $request->validate([
            'amount' => 'sometimes|numeric|min:0.01',
        ]);
        $amount = $validated['amount'] ?? (float) $paymentProof->amount;

        $loan = $paymentProof->loan;
        $payment = $loan->recordPayment(
            $amount,
            "Approved from uploaded GCash screenshot (proof #{$paymentProof->id})",
            $request->user()->id
        );

        $paymentProof->update([
            'status' => 'approved',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'loan_payment_id' => $payment->id,
        ]);

        $this->tryNotify($loan, fn () => "Hi {$loan->name}, thank you for your payment of ₱".number_format($amount, 2)
            ." on loan {$loan->loan_number}! It has been confirmed. Your current balance is ₱"
            .number_format($loan->fresh()->balance, 2).'.');

        return response()->json(['proof' => $paymentProof->fresh(), 'loan' => $loan->fresh()]);
    }

    /**
     * Admin: reject a proof — the note is required and is exactly what gets
     * texted to the borrower explaining why.
     */
    public function reject(Request $request, PaymentProof $paymentProof)
    {
        if ($paymentProof->status !== 'pending') {
            return response()->json(['message' => 'This proof has already been reviewed.'], 422);
        }

        $validated = $request->validate([
            'note' => 'required|string|max:1000',
        ]);

        $paymentProof->update([
            'status' => 'rejected',
            'note' => $validated['note'],
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        $loan = $paymentProof->loan;
        $this->tryNotify($loan, fn () => "Hi {$loan->name}, your submitted payment proof for loan {$loan->loan_number} "
            ."was not approved: {$validated['note']}");

        return response()->json($paymentProof->fresh());
    }

    /**
     * Sends an SMS if the loan has a phone number, logging (not throwing) on
     * failure — approving/rejecting a proof must succeed regardless of
     * whether the notification about it could be delivered.
     */
    private function tryNotify(Loan $loan, callable $message): void
    {
        if (! $loan->phone) {
            return;
        }

        try {
            $this->sms->send($loan->phone, $message());
        } catch (Throwable $e) {
            Log::warning("Failed to send payment-review SMS for loan {$loan->id}: {$e->getMessage()}");
        }
    }
}
