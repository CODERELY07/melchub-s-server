<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use App\Models\Setting;
use App\Services\SmsGatewayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class NotificationController extends Controller
{
    public function __construct(private SmsGatewayService $sms)
    {
    }

    /**
     * Send an admin-authored, free-form SMS to a single borrower — no
     * due-date logic, no fee/status side effects, just whatever the admin
     * typed. Separate from notify() below, which composes its own message
     * text and can mutate the loan (late fee, due date) as a consequence of
     * sending it.
     */
    public function sendCustom(Request $request, Loan $loan)
    {
        if (! $loan->phone) {
            return response()->json(['message' => 'This loan has no phone number on file.'], 422);
        }

        $validated = $request->validate([
            'message' => 'required|string|max:640',
        ]);

        $message = $validated['message'].$this->accountLinkLine();

        try {
            $this->sms->send($loan->phone, $message);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $loan->forceFill(['last_notified_at' => now()])->save();

        return response()->json(['message' => 'Sent', 'loan' => $loan->fresh()]);
    }

    /**
     * Notify a single borrower about their loan.
     */
    public function notify(Loan $loan)
    {
        if (! $loan->phone) {
            return response()->json(['message' => 'This loan has no phone number on file.'], 422);
        }

        ['text' => $message, 'apply' => $apply] = $this->composeMessage($loan);

        try {
            $this->sms->send($loan->phone, $message);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($apply) {
            $apply();
        }
        $loan->forceFill(['last_notified_at' => now()])->save();

        return response()->json(['message' => 'Notified', 'loan' => $loan->fresh()]);
    }

    /**
     * Notify every borrower whose loan is due today or overdue, in one click.
     */
    public function notifyAllDue()
    {
        $loans = Loan::query()
            ->whereNotIn('status', Loan::CLOSED_STATUSES)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<=', today())
            ->whereNotNull('phone')
            ->get();

        $sent = [];
        $failed = [];

        foreach ($loans as $loan) {
            try {
                ['text' => $message, 'apply' => $apply] = $this->composeMessage($loan);
                $this->sms->send($loan->phone, $message);

                if ($apply) {
                    $apply();
                }
                $loan->forceFill(['last_notified_at' => now()])->save();
                $sent[] = $loan->loan_number;
            } catch (Throwable $e) {
                Log::warning("Failed to notify loan {$loan->id} ({$loan->loan_number}): {$e->getMessage()}");
                $failed[] = $loan->loan_number;
            }
        }

        return response()->json(['sent' => $sent, 'failed' => $failed]);
    }

    /**
     * Builds the SMS text for a loan's current due status, and — for an
     * overdue loan not already notified today — an $apply callback that
     * charges the ₱15 late fee and pushes the due date out a week.
     *
     * Deliberately split from applying the fee: the fee/extension must only
     * take effect once the SMS has actually been sent, so a failed send
     * (bad gateway config, network error) can never silently charge a
     * borrower who was never told about it.
     *
     * @return array{text: string, apply: (callable(): void)|null}
     */
    private function composeMessage(Loan $loan): array
    {
        $gcashName = Setting::get('gcash_name', '');
        $gcashNumber = Setting::get('gcash_number', '');
        $gcashLine = $gcashNumber
            ? " Please pay via GCash: {$gcashNumber}".($gcashName ? " ({$gcashName})" : '').'.'
            : '';
        $linkLine = $this->accountLinkLine();

        if (! $loan->due_date) {
            return [
                'text' => "Hi {$loan->name}, this is a reminder from MELCHUB about your loan {$loan->loan_number}. "
                    .'Current balance: ₱'.number_format($loan->balance, 2).".{$gcashLine}{$linkLine}",
                'apply' => null,
            ];
        }

        $alreadyNotifiedToday = $loan->last_notified_at && $loan->last_notified_at->isToday();

        if ($loan->due_date->lt(today())) {
            if ($alreadyNotifiedToday) {
                return [
                    'text' => "Hi {$loan->name}, your MELCHUB loan {$loan->loan_number} is still LATE. "
                        .'Balance due: ₱'.number_format($loan->balance, 2).', due '.$loan->due_date->format('M d, Y').".{$gcashLine}{$linkLine}",
                    'apply' => null,
                ];
            }

            $newDueDate = $loan->due_date->copy()->addWeek();
            $projectedBalance = round((float) $loan->balance + 15, 2);

            return [
                'text' => "Hi {$loan->name}, your MELCHUB loan {$loan->loan_number} is now LATE. "
                    .'A ₱15.00 late fee has been added and your due date moved to '.$newDueDate->format('M d, Y').'. '
                    .'New balance: ₱'.number_format($projectedBalance, 2).".{$gcashLine}{$linkLine}",
                'apply' => function () use ($loan, $newDueDate) {
                    $loan->chargePenalty(15, 'Late payment fee (auto-applied when notifying an overdue loan)');
                    $loan->due_date = $newDueDate;
                    $loan->save();
                },
            ];
        }

        if ($loan->due_date->isToday()) {
            return [
                'text' => "Hi {$loan->name}, your MELCHUB loan {$loan->loan_number} payment of ₱"
                    .number_format($loan->balance, 2).' is due TODAY ('.$loan->due_date->format('M d, Y').").{$gcashLine}{$linkLine}",
                'apply' => null,
            ];
        }

        return [
            'text' => "Hi {$loan->name}, reminder: your MELCHUB loan {$loan->loan_number} of ₱"
                .number_format($loan->balance, 2).' is due on '.$loan->due_date->format('M d, Y').".{$gcashLine}{$linkLine}",
            'apply' => null,
        ];
    }

    /**
     * " View your account: https://..." (or "" if FRONTEND_URL is unset) —
     * shared by composeMessage() and sendCustom() so every outgoing SMS,
     * whether auto-composed or admin-typed, ends with a tappable link to the
     * site. Root "/" there already redirects to the right place for whoever
     * opens it (see client/app/page.tsx), and on Android with the PWA
     * installed, the OS may open it in the installed app instead of a
     * browser tab.
     */
    private function accountLinkLine(): string
    {
        $url = rtrim((string) config('services.frontend_url'), '/');

        return $url ? " View your account: {$url}" : '';
    }
}
