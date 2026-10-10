<?php

namespace App\Http\Controllers;

use App\Models\Borrower;
use App\Models\Loan;
use App\Models\PaymentProof;
use App\Models\Setting;
use App\Services\SmsGatewayService;
use App\Services\SupabaseStorageService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
     * Borrower: submit a GCash screenshot as proof of payment — either for
     * one of their own loans (`loan_id`, from that loan's Pay page) or for
     * all of them at once (`all_loans`, from the Pay-all page), in which
     * case it is split across their loans when the admin approves it. An
     * all-loans receipt can't be more than the total they still owe.
     */
    public function store(Request $request)
    {
        $borrower = $request->user();

        $validated = $request->validate([
            'loan_id' => 'required_without:all_loans|nullable|integer',
            'all_loans' => 'sometimes|boolean',
            'amount' => 'required|numeric|min:0.01',
            'screenshot' => 'required|image|max:10240',
        ]);

        $loan = null;
        if (! $request->boolean('all_loans')) {
            $loan = $borrower->loans()->find($validated['loan_id']);
            if (! $loan) {
                throw new NotFoundHttpException();
            }
        } else {
            $owed = round((float) $borrower->loansForPayment()->sum(fn (Loan $l) => $l->balance), 2);
            if ($owed <= 0) {
                return response()->json(['message' => 'You have nothing left to pay.'], 422);
            }
            if ((float) $validated['amount'] > $owed + 0.009) {
                return response()->json([
                    'message' => 'That is more than the total you still owe (₱'.number_format($owed, 2).').',
                ], 422);
            }
        }

        $prefix = $loan ? $loan->loan_number : 'ALL';

        try {
            $upload = $this->storage->upload(
                $validated['screenshot'],
                "{$prefix}-{$borrower->username}-".now()->format('Ymd_His').'.'.$validated['screenshot']->extension()
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $proof = PaymentProof::create([
            'loan_id' => $loan?->id,
            'borrower_id' => $borrower->id,
            'amount' => $validated['amount'],
            'file_path' => $upload['id'],
            'file_url' => $upload['url'],
            'status' => 'pending',
        ]);

        $this->tryNotifyAdminOfNewProof($borrower, $loan, $proof);

        return response()->json($proof, 201);
    }

    /**
     * Borrower: their own submitted proofs, most recent first — optionally
     * narrowed to one loan (`loan_id`=id, for that loan's Pay page) or to
     * the all-loans ones (`loan_id`=all, for the Pay-all page).
     */
    public function mine(Request $request)
    {
        $query = $request->user()->paymentProofs()->latest();

        if ($loanId = $request->input('loan_id')) {
            $loanId === 'all' ? $query->whereNull('loan_id') : $query->where('loan_id', $loanId);
        }

        return response()->json($query->get());
    }

    /**
     * Admin: every submitted proof (optionally filtered by status).
     */
    public function index(Request $request)
    {
        $query = PaymentProof::with([
            'loan.borrower:id,name,phone',
            'borrower:id,name,phone',
            'reviewer:id,name',
        ])->latest();

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        return response()->json($query->get());
    }

    /**
     * Admin: approve a proof — records the payment and thanks the borrower
     * by SMS. A one-loan proof goes on that loan; an all-loans proof is
     * split across the borrower's loans (Borrower::recordPayment()), and
     * the split is written into the proof's note so it can be checked later.
     */
    public function approve(Request $request, PaymentProof $paymentProof)
    {
        if ($paymentProof->status !== 'pending') {
            return response()->json(['message' => 'This proof has already been reviewed.'], 422);
        }

        $validated = $request->validate([
            'amount' => 'sometimes|numeric|min:0.01',
        ]);
        $amount = (float) ($validated['amount'] ?? $paymentProof->amount);
        $reviewerId = $request->user()->id;
        $note = "Approved from uploaded GCash screenshot (proof #{$paymentProof->id})";

        if ($paymentProof->loan_id === null) {
            return $this->approveForAllLoans($paymentProof, $amount, $note, $reviewerId);
        }

        $loan = $paymentProof->loan;
        $payment = $loan->recordPayment($amount, $note, $reviewerId);

        $paymentProof->update([
            'status' => 'approved',
            'reviewed_by' => $reviewerId,
            'reviewed_at' => now(),
            'loan_payment_id' => $payment->id,
        ]);

        $this->tryNotify($loan->borrower, $loan->id, fn () => "Hi {$loan->name}, thank you for your payment of ₱".number_format($amount, 2)
            ." on loan {$loan->loan_number}! It has been confirmed. Your current balance is ₱"
            .number_format($loan->fresh()->balance, 2).'.');

        return response()->json(['proof' => $paymentProof->fresh(), 'loan' => $loan->fresh()]);
    }

    private function approveForAllLoans(PaymentProof $paymentProof, float $amount, string $note, int $reviewerId)
    {
        $borrower = $paymentProof->borrower;
        if (! $borrower) {
            return response()->json(['message' => 'This proof has no borrower.'], 422);
        }

        try {
            $allocation = DB::transaction(function () use ($borrower, $amount, $note, $reviewerId, $paymentProof) {
                $allocation = $borrower->recordPayment($amount, $note, $reviewerId);

                $paymentProof->update([
                    'status' => 'approved',
                    'reviewed_by' => $reviewerId,
                    'reviewed_at' => now(),
                    // The first share's payment; the full split is in the note.
                    'loan_payment_id' => $allocation[0]['payment_id'] ?? null,
                    'note' => 'Split: '.collect($allocation)
                        ->map(fn ($share) => "{$share['loan_number']} ₱".number_format($share['amount'], 2))
                        ->implode(', '),
                ]);

                return $allocation;
            });
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $owed = round((float) $borrower->loansForPayment()->sum(fn (Loan $l) => $l->balance), 2);
        $this->tryNotify($borrower, $allocation[0]['loan_id'] ?? null, fn () => "Hi {$borrower->name}, thank you for your payment of ₱"
            .number_format($amount, 2).' for your loans! It has been confirmed. '
            .($owed > 0 ? 'Total still owed: ₱'.number_format($owed, 2).'.' : 'All your loans are now fully paid.'));

        return response()->json([
            'proof' => $paymentProof->fresh(),
            'allocation' => $allocation,
            'loans' => $borrower->loans()->whereIn('id', array_column($allocation, 'loan_id'))->get(),
        ]);
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
        $borrower = $loan?->borrower ?? $paymentProof->borrower;
        $what = $loan ? "for loan {$loan->loan_number}" : 'for all your loans';

        $this->tryNotify($borrower, $loan?->id, fn () => "Hi {$borrower->name}, your submitted payment proof {$what} "
            ."was not approved: {$validated['note']}");

        return response()->json($paymentProof->fresh());
    }

    /**
     * Sends an SMS if the borrower has a phone number, logging (not
     * throwing) on failure — approving/rejecting a proof must succeed
     * regardless of whether the notification about it could be delivered.
     */
    private function tryNotify(?Borrower $borrower, ?int $loanId, callable $message): void
    {
        if (! $borrower?->phone) {
            return;
        }

        try {
            $this->sms->send($borrower->phone, $message(), $loanId);
        } catch (Throwable $e) {
            Log::warning("Failed to send payment-review SMS for borrower {$borrower->id}: {$e->getMessage()}");
        }
    }

    /**
     * Lets an admin know a new proof is waiting on review without having to
     * keep the Payment Proofs page open — best-effort and silent if no
     * notification phone is configured (Settings), matching every other
     * SMS side effect in this app. Deliberately not tied to a loan id in the
     * SmsLog audit trail (Part of sms-and-payments.md Step 2b) since the
     * recipient here is staff, not the borrower.
     */
    private function tryNotifyAdminOfNewProof(Borrower $borrower, ?Loan $loan, PaymentProof $proof): void
    {
        $adminPhone = Setting::get('admin_notify_phone');
        if (! $adminPhone) {
            return;
        }

        $what = $loan ? "loan {$loan->loan_number}" : 'all loans';

        try {
            $this->sms->send(
                $adminPhone,
                "New payment proof from {$borrower->name} ({$what}) for ₱"
                    .number_format((float) $proof->amount, 2).'.'
            );
        } catch (Throwable $e) {
            Log::warning("Failed to send new-payment-proof admin alert for proof {$proof->id}: {$e->getMessage()}");
        }
    }
}
