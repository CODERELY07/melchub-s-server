<?php

use App\Models\Loan;
use App\Models\LoanInterestEntry;
use App\Models\LoanPenalty;
use App\Models\RepaymentPlan;
use App\Models\Setting;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The money logic — daily compound interest and the automatic weekly late
 * fee — tested against a throwaway in-memory SQLite database
 * (phpunit.xml). The real migrations use Postgres-only SQL, so this builds
 * the few tables these features touch by hand.
 *
 * The first thing every test does is refuse to run unless the connection
 * really is SQLite: local dev and production share one live database, and
 * creating/dropping tables against it would be a disaster.
 */
beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        throw new RuntimeException('Refusing to run: tests must use the in-memory SQLite database, not the real one.');
    }

    Schema::create('settings', function (Blueprint $t) {
        $t->string('key')->primary();
        $t->text('value')->nullable();
        $t->timestamps();
    });
    Schema::create('repayment_plans', function (Blueprint $t) {
        $t->id();
        $t->string('key')->unique();
        $t->string('name');
        $t->unsignedSmallInteger('period_days');
        $t->unsignedSmallInteger('installments');
        $t->decimal('daily_rate', 6, 3)->default(0);
        $t->boolean('is_active')->default(true);
        $t->timestamps();
    });
    Schema::create('loans', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('borrower_id')->nullable();
        $t->string('loan_number')->nullable();
        $t->decimal('total_loan', 12, 2)->default(0);
        $t->decimal('total_paid', 12, 2)->default(0);
        $t->decimal('interest_rate', 5, 2)->default(0);
        $t->boolean('compounds_interest')->default(true);
        $t->string('repayment_plan')->default('weekly');
        $t->boolean('installments_enabled')->default(true);
        $t->boolean('auto_penalty')->default(true);
        $t->date('auto_penalty_from')->nullable();
        $t->decimal('penalty_amount', 12, 2)->default(0);
        $t->string('status')->default('active');
        $t->text('notes')->nullable();
        $t->date('start_date')->nullable();
        $t->date('due_date')->nullable();
        $t->date('closed_at')->nullable();
        $t->timestamp('last_notified_at')->nullable();
        $t->unsignedBigInteger('created_by')->nullable();
        $t->timestamps();
        $t->softDeletes();
    });
    Schema::create('loan_payments', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('loan_id');
        $t->decimal('amount', 12, 2);
        $t->text('note')->nullable();
        $t->date('paid_at');
        $t->unsignedBigInteger('recorded_by')->nullable();
        $t->timestamps();
    });
    Schema::create('loan_penalties', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('loan_id');
        $t->decimal('amount', 12, 2);
        $t->text('reason')->nullable();
        $t->date('charged_at');
        $t->timestamps();
    });
    Schema::create('loan_interest_entries', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('loan_id');
        $t->date('entry_date');
        $t->decimal('interest_amount', 12, 2);
        $t->decimal('running_balance', 12, 2);
        $t->timestamps();
        $t->unique(['loan_id', 'entry_date']);
    });

    RepaymentPlan::flushCache();
    Loan::flushBudgetCache();
    RepaymentPlan::create(['key' => 'weekly', 'name' => '1-Week Plan', 'period_days' => 7, 'installments' => 5, 'daily_rate' => 0.4]);
    Setting::set('late_fee_amount', '50');
});

/** Insert a loan row directly — no model events, and nothing has opened it yet. */
function insertLoan(array $attributes): int
{
    return DB::table('loans')->insertGetId(array_merge([
        'total_loan' => 1000,
        'total_paid' => 0,
        'interest_rate' => 0.4,
        'compounds_interest' => false,
        'repayment_plan' => 'weekly',
        'installments_enabled' => true,
        'auto_penalty' => false,
        'penalty_amount' => 0,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ], $attributes));
}

/** Insert a loan, then load it the way the app does — which runs the catch-ups. */
function makeLoan(array $attributes): Loan
{
    return Loan::findOrFail(insertLoan($attributes));
}

function daysAgo(int $days): string
{
    return today()->subDays($days)->toDateString();
}

// ---------------------------------------------------------------- interest

it('records one compounded interest entry per day, each on the running balance', function () {
    $loan = makeLoan([
        'compounds_interest' => true,
        'start_date' => daysAgo(3),
        'due_date' => today()->addDays(4)->toDateString(),
    ]);

    $balances = LoanInterestEntry::where('loan_id', $loan->id)->orderBy('entry_date')->pluck('running_balance')->map(fn ($v) => (float) $v)->all();

    expect($balances)->toBe([1004.0, 1008.02, 1012.05, 1016.1])
        ->and($loan->interest_amount)->toBe(16.1)
        ->and($loan->balance)->toBe(1016.1);
});

it('does not write duplicate interest entries when the loan is opened again', function () {
    $loan = makeLoan(['compounds_interest' => true, 'start_date' => daysAgo(3), 'due_date' => today()->addDays(4)->toDateString()]);

    Loan::find($loan->id);
    Loan::find($loan->id);

    expect(LoanInterestEntry::where('loan_id', $loan->id)->count())->toBe(4);
});

it('keeps simple interest on loans that do not compound', function () {
    $loan = makeLoan(['compounds_interest' => false, 'start_date' => daysAgo(3), 'due_date' => today()->addDays(4)->toDateString()]);

    expect($loan->interest_amount)->toBe(16.0)
        ->and(LoanInterestEntry::count())->toBe(0);
});

it('rebuilds recorded interest when a compounding loan principal is edited', function () {
    $loan = makeLoan(['compounds_interest' => true, 'start_date' => daysAgo(1), 'due_date' => today()->addDays(6)->toDateString()]);
    expect($loan->interest_amount)->toBe(8.02);

    $loan->update(['total_loan' => 2000]);
    $fresh = Loan::find($loan->id);

    expect($fresh->interest_amount)->toBe(16.03)
        ->and(LoanInterestEntry::where('loan_id', $loan->id)->count())->toBe(2);
});

// ------------------------------------------------------ automatic penalty

// Weekly plan, ₱1,000 at 0.4%/day: one installment = 1000 × (1/5 + 0.004 × 7) = ₱228.
// Start 23 days ago, first deadline at day 7: deadlines 16, 9 and 2 days ago have passed.

it('charges the late fee once for each missed week and rolls the due date forward', function () {
    $start = daysAgo(23);
    $loan = makeLoan([
        'auto_penalty' => true,
        'auto_penalty_from' => $start,
        'start_date' => $start,
        'due_date' => today()->subDays(16)->toDateString(),
    ]);

    expect(LoanPenalty::where('loan_id', $loan->id)->count())->toBe(3)
        ->and((float) $loan->penalty_amount)->toBe(150.0)
        ->and($loan->due_date->toDateString())->toBe(today()->addDays(5)->toDateString())
        ->and($loan->amount_due_this_week)->toBe(912.0)
        ->and($loan->past_due_amount)->toBe(684.0)
        ->and($loan->is_overdue)->toBeTrue();
});

it('does not charge the same week twice when the loan is opened again', function () {
    $start = daysAgo(23);
    $loan = makeLoan(['auto_penalty' => true, 'auto_penalty_from' => $start, 'start_date' => $start, 'due_date' => today()->subDays(16)->toDateString()]);

    Loan::find($loan->id);
    Loan::find($loan->id);

    expect(LoanPenalty::where('loan_id', $loan->id)->count())->toBe(3);
});

it('charges nothing when the borrower paid ahead of the schedule', function () {
    $start = daysAgo(23);
    $id = insertLoan([
        'auto_penalty' => true,
        'auto_penalty_from' => $start,
        'start_date' => $start,
        'due_date' => today()->subDays(16)->toDateString(),
        'total_paid' => 684, // three installments
    ]);
    DB::table('loan_payments')->insert(['loan_id' => $id, 'amount' => 684, 'paid_at' => $start, 'created_at' => now(), 'updated_at' => now()]);

    $loan = Loan::find($id);

    expect(LoanPenalty::where('loan_id', $loan->id)->count())->toBe(0)
        ->and($loan->past_due_amount)->toBe(0.0)
        ->and($loan->amount_due_this_week)->toBe(228.0)
        ->and($loan->is_overdue)->toBeFalse();
});

it('does not let a payment made after a deadline save that week', function () {
    $start = daysAgo(23);
    $id = insertLoan(['auto_penalty' => true, 'auto_penalty_from' => $start, 'start_date' => $start, 'due_date' => today()->subDays(16)->toDateString(), 'total_paid' => 684]);
    // Recorded yesterday, after all three deadlines had passed — and nobody
    // opened the loan in between, so the catch-up sees it for the first time now.
    DB::table('loan_payments')->insert(['loan_id' => $id, 'amount' => 684, 'paid_at' => daysAgo(1), 'created_at' => now(), 'updated_at' => now()]);

    Loan::find($id);

    expect(LoanPenalty::where('loan_id', $id)->count())->toBe(3);
});

it('never charges weeks that passed before the option was turned on', function () {
    $loan = makeLoan([
        'auto_penalty' => true,
        'auto_penalty_from' => today()->toDateString(),
        'start_date' => daysAgo(23),
        'due_date' => today()->subDays(16)->toDateString(),
    ]);

    expect(LoanPenalty::where('loan_id', $loan->id)->count())->toBe(0)
        ->and($loan->due_date->toDateString())->toBe(today()->addDays(5)->toDateString());
});

it('leaves loans without the option on to the manual Notify flow', function () {
    $loan = makeLoan(['auto_penalty' => false, 'start_date' => daysAgo(23), 'due_date' => today()->subDays(16)->toDateString()]);

    expect(LoanPenalty::count())->toBe(0)
        ->and($loan->due_date->toDateString())->toBe(today()->subDays(16)->toDateString());
});

it('keeps charging weekly after the last installment until the schedule is paid', function () {
    // 9 weeks in: 5 planned weeks + 4 more, nothing paid.
    $start = daysAgo(64);
    $loan = makeLoan(['auto_penalty' => true, 'auto_penalty_from' => $start, 'start_date' => $start, 'due_date' => today()->subDays(57)->toDateString()]);

    expect(LoanPenalty::where('loan_id', $loan->id)->count())->toBe(9)
        ->and($loan->amount_due_this_week)->toBe(1140.0); // capped at all 5 installments
});

it('skips the automatic fee when the admin set the late fee to zero', function () {
    Setting::set('late_fee_amount', '0');
    $start = daysAgo(23);
    $loan = makeLoan(['auto_penalty' => true, 'auto_penalty_from' => $start, 'start_date' => $start, 'due_date' => today()->subDays(16)->toDateString()]);

    expect(LoanPenalty::count())->toBe(0)
        ->and($loan->due_date->toDateString())->toBe(today()->addDays(5)->toDateString());
});
