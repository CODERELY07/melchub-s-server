<?php

namespace App\Http\Controllers;

use App\Models\Borrower;
use App\Models\Loan;
use App\Models\Setting;
use App\Services\SmsGatewayService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;

class BorrowersController extends Controller
{
    public function __construct(private SmsGatewayService $sms)
    {
    }

    /**
     * Staff: every borrower, for the "give an existing borrower a new loan"
     * picker on the Loans page (client/app/admin/loans/page.tsx). Small
     * dataset in practice, so no pagination — optionally filtered by name/
     * username for a quick search box.
     */
    public function index(Request $request)
    {
        $query = Borrower::query()->withCount('loans')->orderBy('name');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%");
            });
        }

        return response()->json($query->get());
    }

    /**
     * Admin: the person's loans that a combined payment would go to, in
     * the order it fills them — for the Record-payment preview, which must
     * not depend on whatever status filter the Loans list is showing.
     */
    public function openLoans(Borrower $borrower)
    {
        return response()->json($borrower->loansForPayment());
    }

    /**
     * Admin: one payment for all of a person's loans — split across them
     * soonest due first (Borrower::recordPayment()). 422 if it is more than
     * the total they still owe.
     */
    public function recordPayment(Request $request, Borrower $borrower)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'note' => 'sometimes|nullable|string|max:1000',
            'paid_at' => 'sometimes|date',
        ]);

        try {
            $allocation = $borrower->recordPayment(
                (float) $validated['amount'],
                $validated['note'] ?? null,
                $request->user()->id,
                isset($validated['paid_at']) ? Carbon::parse($validated['paid_at']) : null
            );
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'allocation' => $allocation,
            'loans' => $borrower->loans()->whereIn('id', array_column($allocation, 'loan_id'))->get(),
        ], 201);
    }

    /**
     * Admin: ONE reminder text for a person with several loans — what to
     * pay by the soonest deadline across all of them, and the total still
     * owed. Only a reminder: unlike the per-loan Notify it never charges a
     * fee or moves a due date, so it is safe to send any time.
     */
    public function notify(Borrower $borrower)
    {
        if (! $borrower->phone) {
            return response()->json(['message' => 'This person has no phone number on file.'], 422);
        }

        $loans = $borrower->loansForPayment();
        if ($loans->isEmpty()) {
            return response()->json(['message' => 'This person has no unpaid loans.'], 422);
        }

        $message = $this->composeTotalsMessage($borrower, $loans);

        try {
            // Logged against the soonest-due loan so it shows in that loan's SMS history.
            $this->sms->send($borrower->phone, $message, $loans->first()->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Notified', 'text' => $message]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Loan>  $loans
     */
    private function composeTotalsMessage(Borrower $borrower, $loans): string
    {
        $gcashName = Setting::get('gcash_name', '');
        $gcashNumber = Setting::get('gcash_number', '');
        $gcashLine = $gcashNumber
            ? " Please pay via GCash: {$gcashNumber}".($gcashName ? " ({$gcashName})" : '').'.'
            : '';

        $owed = round((float) $loans->sum(fn (Loan $loan) => $loan->balance), 2);
        $due = round((float) $loans->sum(fn (Loan $loan) => $loan->amount_due_this_week), 2);
        $late = round((float) $loans->sum(fn (Loan $loan) => $loan->past_due_amount), 2);

        $count = $loans->count();
        $which = $count === 1
            ? "your loan {$loans->first()->loan_number}"
            : "your {$count} loans";

        $nextDue = $loans
            ->filter(fn (Loan $loan) => $loan->due_date && $loan->amount_due_this_week > 0)
            ->min(fn (Loan $loan) => $loan->due_date->toDateString());

        if ($due <= 0) {
            $next = $loans->filter(fn (Loan $loan) => $loan->due_date)->min(fn (Loan $loan) => $loan->due_date->toDateString());

            return "Hi {$borrower->name}, you are paid up for now on {$which} with MELCHUB."
                .($next ? ' Next payment is due '.Carbon::parse($next)->format('M d, Y').'.' : '')
                .' Total still owed: ₱'.number_format($owed, 2).'.';
        }

        $when = match (true) {
            $nextDue === null => '',
            Carbon::parse($nextDue)->isToday() => ' TODAY',
            default => ' by '.Carbon::parse($nextDue)->format('M d, Y'),
        };
        $lateLine = $late > 0 ? ' (₱'.number_format($late, 2).' of it is already late)' : '';
        $totalLine = $owed > $due ? ' Total still owed on all: ₱'.number_format($owed, 2).'.' : '';

        return "Hi {$borrower->name}, MELCHUB reminder for {$which}: please pay ₱".number_format($due, 2)
            ."{$when}{$lateLine}.{$totalLine}{$gcashLine}";
    }
}
