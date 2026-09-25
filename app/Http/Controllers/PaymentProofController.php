<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use App\Models\PaymentProof;
use App\Models\Setting;
use App\Services\SmsGatewayService;
use App\Services\SupabaseStorageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class PaymentProofController extends Controller
{
    public function __construct(
        private SupabaseStorageService $storage,
        private SmsGatewayService $sms,
    ) {
    }

    /**
     * Borrower: submit a GCash screenshot as proof of payment against one
     * of their own loans (a borrower can have more than one, so they say
     * which — the frontend already knows since the Pay page is per-loan).
     */
    public function store(Request $request)
    {
        $borrower = $request->user();

        $validated = $request->validate([
            'loan_id' => 'required|integer',
            'amount' => 'required|numeric|min:0.01',
            'screenshot' => 'required|image|max:10240',
        ]);

        $loan = $borrower->loans()->find($validated['loan_id']);
        if (! $loan) {
            throw new NotFoundHttpException();
        }

        try {
            $upload = $this->storage->upload(
                $validated['screenshot'],
                "{$loan->loan_number}-{$borrower->username}-".now()->format('Ymd_His').'.'.$validated['screenshot']->extension()
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

        $this->tryNotifyAdminOfNewProof($loan, $proof);

        return response()->json($proof, 201);
    }

    /**
     * Borrower: their own submitted proofs, most recent first — optionally
     * narrowed to one loan (the per-loan Pay page only wants that loan's).
     */
    public function mine(Request $request)
    {
        $query = $request->user()->paymentProofs()->latest();

        if ($loanId = $request->input('loan_id')) {
            $query->where('loan_id', $loanId);
        }

        return response()->json($query->get());
    }

    /**
     * Admin: every submitted proof (optionally filtered by status).
     */
    public function index(Request $request)
    {
        $query = PaymentProof::with(['loan.borrower:id,name,phone', 'reviewer:id,name'])->latest();

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
            $this->sms->send($loan->phone, $message(), $loan->id);
        } catch (Throwable $e) {
            Log::warning("Failed to send payment-review SMS for loan {$loan->id}: {$e->getMessage()}");
        }
    }

    /**
     * Lets an admin know a new proof is waiting on review without having to
     * keep the Payment Proofs page open — best-effort and silent if no
     * notification phone is configured (Settings), matching every other
     * SMS side effect in this app. Deliberately not tied to $loan->id in the
     * SmsLog audit trail (Part of sms-and-payments.md Step 2b) since the
     * recipient here is staff, not that loan's borrower.
     */
    private function tryNotifyAdminOfNewProof(Loan $loan, PaymentProof $proof): void
    {
        $adminPhone = Setting::get('admin_notify_phone');
        if (! $adminPhone) {
            return;
        }

        try {
            $this->sms->send(
                $adminPhone,
                "New payment proof from {$loan->name} (loan {$loan->loan_number}) for ₱"
                    .number_format((float) $proof->amount, 2).'.'
            );
        } catch (Throwable $e) {
            Log::warning("Failed to send new-payment-proof admin alert for proof {$proof->id}: {$e->getMessage()}");
        }
    }
}
