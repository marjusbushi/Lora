<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\FinanceAccount;
use App\Models\FinancePayment;
use App\Models\Invoice;
use App\Models\Reservation;
use App\Models\Setting;
use App\Models\Supplier;
use App\Services\CurrencyRates;
use App\Tenancy\TenantRule;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Finance module (Phase 1): Paneli + Arka & Banka + Pagesat.
 * The ledger is fed automatically (FinanceLedger); these screens read it and
 * accept the few MANUAL movements: ad-hoc payments and transfers.
 * Bank accounts are visible only with view_bank_accounts — everywhere.
 */
class FinanceController extends Controller
{
    public function index(Request $request): Response
    {
        FinanceAccount::ensureDefaults();
        $accounts = $this->visibleAccounts($request);
        $now = CarbonImmutable::now();
        $today = $now->startOfDay();
        $period = in_array($request->string('period')->toString(), ['today', 'month', 'year'], true)
            ? $request->string('period')->toString()
            : 'month';

        [$periodStart, $periodEnd, $previousStart, $previousEnd, $periodLabel, $comparisonLabel] = match ($period) {
            'today' => [
                $today,
                $now,
                $today->subDay(),
                $now->subDay(),
                'Sot',
                'krahasuar me dje',
            ],
            'year' => [
                $now->startOfYear(),
                $now,
                $now->subYear()->startOfYear(),
                $now->subYear(),
                'Ky vit',
                'krahasuar me të njëjtën periudhë vjet',
            ],
            default => [
                $now->startOfMonth(),
                $now,
                $now->subMonthNoOverflow()->startOfMonth(),
                $now->subMonthNoOverflow(),
                'Ky muaj',
                'krahasuar me të njëjtën periudhë muajin e kaluar',
            ],
        };

        $currentSummary = $this->cashSummary($periodStart, $periodEnd);
        $previousSummary = $this->cashSummary($previousStart, $previousEnd);
        $summary = array_merge($currentSummary, [
            'period' => $period,
            'period_label' => $periodLabel,
            'comparison_label' => $comparisonLabel,
            'income_change' => $this->percentageChange($currentSummary['income'], $previousSummary['income']),
            'expenses_change' => $this->percentageChange($currentSummary['expenses'], $previousSummary['expenses']),
            'net_change' => $this->percentageChange($currentSummary['net'], $previousSummary['net']),
        ]);

        // 14-day cash-flow in base EUR (transfers move money between our own
        // pockets — they are neither income nor spend, so they stay out).
        $from = $today->subDays(13);
        $rows = FinancePayment::where('paid_at', '>=', $from->startOfDay())
            ->whereIn('direction', ['in', 'out'])
            ->get(['direction', 'amount_base', 'paid_at'])
            ->groupBy(fn ($p) => $p->paid_at->toDateString());
        $cashflow = [];
        for ($d = $from; $d->lte($today); $d = $d->addDay()) {
            $ofDay = $rows->get($d->toDateString(), collect());
            $cashflow[] = [
                'date' => $d->toDateString(),
                'in' => round((float) $ofDay->where('direction', 'in')->sum('amount_base'), 2),
                'out' => round((float) $ofDay->where('direction', 'out')->sum('amount_base'), 2),
            ];
        }

        $openBills = Bill::with('supplier:id,name')->where('status', '!=', 'paid')->get();
        $openInvoices = Invoice::where('status', '!=', 'paid')->get();
        $receivableTotal = round((float) $openInvoices->sum(fn ($i) => $i->remainingBase()), 2);
        $payableTotal = round((float) $openBills->sum(fn ($b) => $b->remainingBase()), 2);
        $overdueBills = $openBills->filter(fn ($b) => $b->due_date && $b->due_date->lt($today));
        $dueSoonBills = $openBills->filter(fn ($b) => $b->due_date && $b->due_date->gte($today) && $b->due_date->lte($today->addDays(7)));
        $overdueInvoices = $openInvoices->filter(fn ($i) => $i->due_date && $i->due_date->lt($today));
        $invoiceTotal = round((float) Invoice::sum('total'), 2);
        $collectionRate = $invoiceTotal > 0
            ? round(max(0, min(100, (($invoiceTotal - $receivableTotal) / $invoiceTotal) * 100)), 1)
            : 100.0;

        $alerts = $openBills
            ->filter(fn ($b) => $b->due_date && $b->due_date->lte($today))
            ->map(fn ($b) => [
                'label' => 'Bill '.($b->number ?: '#'.$b->id).' — '.($b->supplier?->name ?? ''),
                'amount' => (float) $b->remainingBase(),
                'severity' => 'error',
                'badge' => $b->due_date->isToday() ? 'Afati SOT' : 'Vonesë '.max(1, (int) ceil($b->due_date->diffInDays($today, true))).' ditë',
                'href' => route('finance.bills'),
            ])->values();

        $arkaLimit = (float) Setting::get('financial.arka_limit', 1000);
        $cash = $accounts->firstWhere('type', 'cash');
        if ($cash && $cash['balance'] > $arkaLimit) {
            $alerts->push([
                'label' => 'Arka mbi limitin e sigurisë (€'.number_format($arkaLimit, 0).') — bëj depozitim në bankë',
                'amount' => $cash['balance'],
                'severity' => 'warning',
                'badge' => 'Sugjerim',
                'href' => route('finance.accounts', ['account_id' => $cash['id']]),
            ]);
        }

        return Inertia::render('Finance/Index', array_merge($this->shared($request), [
            'accounts' => $accounts,
            'summary' => $summary,
            'receivables' => [
                'total' => $receivableTotal,
                'count' => $openInvoices->count(),
                'overdue_total' => round((float) $overdueInvoices->sum(fn ($i) => $i->remainingBase()), 2),
                'overdue_count' => $overdueInvoices->count(),
                'collection_rate' => $collectionRate,
            ],
            'payables' => [
                'total' => $payableTotal,
                'count' => $openBills->count(),
                'overdue_total' => round((float) $overdueBills->sum(fn ($b) => $b->remainingBase()), 2),
                'overdue_count' => $overdueBills->count(),
                'due_soon_total' => round((float) $dueSoonBills->sum(fn ($b) => $b->remainingBase()), 2),
                'due_soon_count' => $dueSoonBills->count(),
            ],
            'cashflow' => $cashflow,
            'alerts' => $alerts,
            'latest' => $this->paymentRows(FinancePayment::with(['account:id,name', 'counterAccount:id,name'])
                ->latest('paid_at')->latest('id')->limit(8)->get()),
        ]));
    }

    public function accounts(Request $request): Response
    {
        FinanceAccount::ensureDefaults();
        // This management page also lists DEACTIVATED accounts (dimmed, with
        // their ledger still browsable); every money-moving screen elsewhere
        // uses visibleAccounts(), which is active-only.
        $accounts = $this->manageableAccounts($request);
        $selectedId = (int) $request->input('account_id') ?: ($accounts->first()['id'] ?? null);
        // Never leak a hidden (bank) ledger through a hand-typed account_id.
        if (! $accounts->firstWhere('id', $selectedId)) {
            abort(403);
        }

        // Ledger with a running balance, computed oldest-first then shown newest-first.
        $rows = FinancePayment::with(['account:id,name', 'counterAccount:id,name'])
            ->where(fn ($q) => $q->where('account_id', $selectedId)->orWhere('counter_account_id', $selectedId))
            ->orderBy('paid_at')->orderBy('id')
            ->get();
        $running = 0.0;
        $ledger = $rows->map(function (FinancePayment $p) use (&$running, $selectedId) {
            $delta = match (true) {
                $p->direction === 'in' && $p->account_id === $selectedId => (float) $p->amount,
                $p->direction === 'out' && $p->account_id === $selectedId => -(float) $p->amount,
                $p->direction === 'transfer' && $p->account_id === $selectedId => -(float) $p->amount,
                default => (float) $p->amount, // transfer INTO this account
            };
            $running = round($running + $delta, 2);

            return array_merge($this->paymentRow($p), ['delta' => $delta, 'balance' => $running]);
        })->reverse()->values();

        return Inertia::render('Finance/Accounts', array_merge($this->shared($request), [
            'accounts' => $accounts,
            'selectedId' => $selectedId,
            'ledger' => $ledger,
            'currencies' => ['EUR', 'ALL'],
        ]));
    }

    /** New cash box or bank account (owner-only via manage_finance_settings). */
    public function storeAccount(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'type' => ['required', 'in:cash,bank'],
            // EUR + ALL only for now: every money screen (manual payments,
            // bills) is built around these two; more currencies need Phase 3.
            'currency' => ['required', 'in:EUR,ALL'],
            'iban' => ['nullable', 'string', 'max:40'],
        ]);

        // Unique per tenant (the model's global scope narrows the query).
        if (FinanceAccount::where('name', $data['name'])->exists()) {
            return back()->with('error', "Ekziston tashmë një llogari me emrin \"{$data['name']}\".");
        }

        FinanceAccount::create([
            'name' => $data['name'],
            'type' => $data['type'],
            'currency' => $data['currency'],
            'iban' => $data['type'] === 'bank' ? ($data['iban'] ?? null) : null,
            'is_active' => true,
        ]);

        return back()->with('success', "Llogaria \"{$data['name']}\" u krijua.");
    }

    /**
     * Deactivate / reactivate. Accounts are never DELETED (the ledger keeps
     * pointing at them — history integrity), and the last active account of a
     * type stays: the auto-feed (folio payments, POS shifts) deposits into the
     * first active cash/bank account and would break without one.
     */
    public function toggleAccount(Request $request, FinanceAccount $account): RedirectResponse
    {
        if ($account->is_active) {
            $lastOfType = ! FinanceAccount::where('type', $account->type)
                ->where('is_active', true)->where('id', '!=', $account->id)->exists();
            if ($lastOfType) {
                $lloji = $account->type === 'cash' ? 'arkë' : 'bankë';

                return back()->with('error', "S'mund të çaktivizohet e vetmja {$lloji} aktive — pagesat automatike derdhen aty.");
            }
        }

        $account->update(['is_active' => ! $account->is_active]);

        return back()->with('success', $account->is_active
            ? "Llogaria \"{$account->name}\" u riaktivizua."
            : "Llogaria \"{$account->name}\" u çaktivizua — historiku i saj ruhet.");
    }

    public function payments(Request $request): Response
    {
        FinanceAccount::ensureDefaults();
        $direction = $request->input('direction');
        $q = FinancePayment::with(['account:id,name', 'counterAccount:id,name'])
            ->latest('paid_at')->latest('id');
        if (in_array($direction, ['in', 'out', 'transfer'], true)) {
            $q->where('direction', $direction);
        }
        if ($request->input('source') === 'manual') {
            $q->where('source', 'manual');
        }
        if (! $request->user()->can('view_bank_accounts')) {
            $bankIds = FinanceAccount::where('type', 'bank')->pluck('id');
            $q->whereNotIn('account_id', $bankIds)
                ->where(fn ($w) => $w->whereNull('counter_account_id')->orWhereNotIn('counter_account_id', $bankIds));
        }

        return Inertia::render('Finance/Payments', array_merge($this->shared($request), [
            'accounts' => $this->visibleAccounts($request),
            'filters' => ['direction' => $direction, 'source' => $request->input('source')],
            'payments' => $q->paginate(30)->withQueryString()->through(fn ($p) => $this->paymentRow($p)),
        ]));
    }

    /** Manual movement: an ad-hoc arkëtim (in) or expense (out). */
    public function storePayment(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'direction' => ['required', 'in:in,out'],
            'account_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999'],
            'currency' => ['required', 'in:EUR,ALL'],
            'fx_rate' => ['required_if:currency,ALL', 'nullable', 'numeric', 'min:1'],
            'method' => ['required', 'in:cash,card,bank'],
            'description' => ['required', 'string', 'max:300'],
            'paid_at' => ['nullable', 'date'],
        ]);

        // An outgoing manual payment is spending money — owner/manager only.
        if ($data['direction'] === 'out' && ! $request->user()->can('pay_bills')) {
            abort(403);
        }
        $account = $this->visibleAccounts($request)->firstWhere('id', (int) $data['account_id']);
        if (! $account) {
            abort(403); // never move money on an account this user cannot see
        }
        if ($account['currency'] !== 'EUR' && $data['currency'] !== $account['currency']) {
            return back()->with('error', "Kjo llogari mban vetëm {$account['currency']}.");
        }

        FinancePayment::create([
            'direction' => $data['direction'],
            'account_id' => $account['id'],
            'amount' => $data['amount'],
            'currency' => $data['currency'],
            'fx_rate' => $data['currency'] === 'ALL' ? $data['fx_rate'] : null,
            'method' => $data['method'],
            'source' => 'manual',
            'description' => $data['description'],
            'paid_at' => $data['paid_at'] ?? now(),
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', $data['direction'] === 'in' ? 'Arkëtimi u regjistrua.' : 'Pagesa u regjistrua.');
    }

    /** Transfer between two SAME-currency accounts — one atomic ledger row. */
    public function storeTransfer(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'from_account_id' => ['required', 'integer', 'different:to_account_id'],
            'to_account_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999'],
            'description' => ['nullable', 'string', 'max:300'],
        ]);

        $visible = $this->visibleAccounts($request);
        $from = $visible->firstWhere('id', (int) $data['from_account_id']);
        $to = $visible->firstWhere('id', (int) $data['to_account_id']);
        if (! $from || ! $to) {
            abort(403);
        }
        if ($from['currency'] !== $to['currency']) {
            return back()->with('error', 'Transferta lejohet vetëm mes llogarive me të njëjtën monedhë (Faza 1).');
        }

        DB::transaction(function () use ($data, $from, $to, $request) {
            FinancePayment::create([
                'direction' => 'transfer',
                'account_id' => $from['id'],
                'counter_account_id' => $to['id'],
                'amount' => $data['amount'],
                'currency' => $from['currency'],
                'fx_rate' => null,
                'method' => $from['type'] === 'cash' ? 'cash' : 'bank',
                'source' => 'manual',
                'description' => ($data['description'] ?? null) ?: "Transfertë {$from['name']} → {$to['name']}",
                'paid_at' => now(),
                'created_by' => $request->user()->id,
            ]);
        });

        return back()->with('success', 'Transferta u krye.');
    }

    // -- bills (Blerjet) ------------------------------------------------------

    public function bills(Request $request): Response
    {
        FinanceAccount::ensureDefaults();
        $filter = $request->input('filter');

        // This month's spend per category (paid or not — commitment view),
        // plus the auto OTA commissions which are never ledger rows.
        $monthStart = CarbonImmutable::today()->startOfMonth();
        $byCategory = Bill::where('issue_date', '>=', $monthStart->toDateString())
            ->get(['category', 'total_base'])
            ->groupBy('category')
            ->map(fn ($g) => round((float) $g->sum('total_base'), 2))
            ->sortDesc();
        $commissions = (float) Reservation::whereNotIn('status', ['cancelled'])
            ->whereDate('check_in_date', '>=', $monthStart->toDateString())
            ->sum('commission_amount');
        if ($commissions > 0) {
            $byCategory->put('Komisione OTA (auto)', round($commissions, 2));
        }

        return Inertia::render('Finance/Bills', array_merge($this->shared($request), [
            'accounts' => $this->visibleAccounts($request),
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'categories' => Bill::categories(),
            'filters' => ['filter' => $filter, 'category' => $request->input('category')],
            'byCategory' => $byCategory,
            'bills' => Bill::with('supplier:id,name')->latest('issue_date')->latest('id')
                ->when($filter === 'unpaid', fn ($qq) => $qq->where('status', '!=', 'paid'))
                ->when($filter === 'due', fn ($qq) => $qq->where('status', '!=', 'paid')->whereDate('due_date', '<=', today()))
                ->when($request->filled('category'), fn ($qq) => $qq->where('category', $request->input('category')))
                ->paginate(25)->withQueryString()->through(fn (Bill $b) => $this->billRow($b)),
        ]));
    }

    public function storeBill(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'number' => ['nullable', 'string', 'max:60'],
            'category' => ['required', 'string', 'max:60'],
            'issue_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'currency' => ['required', 'in:EUR,ALL'],
            'fx_rate' => ['required_if:currency,ALL', 'nullable', 'numeric', 'min:1'],
            'total' => ['required', 'numeric', 'min:0.01', 'max:9999999'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        Bill::create($data + ['status' => 'open']);

        return back()->with('success', 'Fatura e blerjes u regjistrua.');
    }

    /** Pay a bill (fully or partially) from a visible account — atomic. */
    public function payBill(Request $request, Bill $bill): RedirectResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', 'in:cash,card,bank'],
        ]);
        $account = $this->visibleAccounts($request)->firstWhere('id', (int) $data['account_id']);
        if (! $account) {
            abort(403);
        }

        if ($account['currency'] !== 'EUR' && strtoupper($bill->currency) !== $account['currency']) {
            return back()->with('error', "Kjo llogari mban vetëm {$account['currency']} — fatura është në {$bill->currency}.");
        }

        // Payment rides the BILL's currency + frozen fx, so remainder math is exact.
        $amountBase = strtoupper($bill->currency) === 'EUR'
            ? round((float) $data['amount'], 2)
            : round((float) $data['amount'] / (float) $bill->fx_rate, 2);
        if ($amountBase > $bill->remainingBase() + 0.01) {
            return back()->with('error', 'Shuma e kalon mbetjen e faturës ('.number_format($bill->remainingBase(), 2).' € mbetje).');
        }

        DB::transaction(function () use ($data, $bill, $account, $request) {
            FinancePayment::create([
                'direction' => 'out',
                'account_id' => $account['id'],
                'amount' => $data['amount'],
                'currency' => $bill->currency,
                'fx_rate' => strtoupper($bill->currency) === 'EUR' ? null : $bill->fx_rate,
                'method' => $data['method'],
                'source' => 'manual',
                'bill_id' => $bill->id,
                'description' => 'Pagesë bill '.($bill->number ?: '#'.$bill->id).' — '.($bill->supplier?->name ?? ''),
                'paid_at' => now(),
                'created_by' => $request->user()->id,
            ]);
            $bill->refreshStatus();
        });

        return back()->with('success', 'Pagesa e faturës u regjistrua.');
    }

    // -- suppliers (Furnitorët) ----------------------------------------------

    public function suppliers(Request $request): Response
    {
        $suppliers = Supplier::withCount('bills')->orderBy('name')->get()->map(fn (Supplier $s) => [
            'id' => $s->id,
            'name' => $s->name,
            'nipt' => $s->nipt,
            'category' => $s->category,
            'phone' => $s->phone,
            'email' => $s->email,
            'address' => $s->address,
            'payment_terms_days' => $s->payment_terms_days,
            'is_active' => $s->is_active,
            'bills_count' => $s->bills_count,
            'open_balance' => $s->openBalanceBase(),
            'ytd' => round((float) $s->bills()->whereYear('issue_date', now()->year)->sum('total_base'), 2),
        ]);

        return Inertia::render('Finance/Suppliers', array_merge($this->shared($request), [
            'suppliers' => $suppliers,
            'categories' => Bill::categories(),
        ]));
    }

    public function storeSupplier(Request $request): RedirectResponse
    {
        Supplier::create($this->supplierData($request));

        return back()->with('success', 'Furnitori u shtua.');
    }

    public function updateSupplier(Request $request, Supplier $supplier): RedirectResponse
    {
        $supplier->update($this->supplierData($request, $supplier->id));

        return back()->with('success', 'Furnitori u përditësua.');
    }

    public function destroySupplier(Supplier $supplier): RedirectResponse
    {
        if ($supplier->bills()->where('status', '!=', 'paid')->exists()) {
            return back()->with('error', 'Ky furnitor ka fatura të papaguara — paguaji ose anuloji përpara se ta heqësh.');
        }
        if ($supplier->bills()->exists()) {
            // History stays intact: deactivate instead of deleting paid history.
            $supplier->update(['is_active' => false]);

            return back()->with('success', 'Furnitori u çaktivizua (historiku i faturave ruhet).');
        }
        $supplier->delete();

        return back()->with('success', 'Furnitori u fshi.');
    }

    private function supplierData(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255', TenantRule::unique('suppliers', 'name')->ignore($ignoreId)],
            'nipt' => ['nullable', 'string', 'max:20'],
            'category' => ['nullable', 'string', 'max:60'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    private function billRow(Bill $b): array
    {
        return [
            'id' => $b->id,
            'number' => $b->number,
            'supplier' => $b->supplier?->name,
            'supplier_id' => $b->supplier_id,
            'category' => $b->category,
            'issue_date' => $b->issue_date->toDateString(),
            'due_date' => $b->due_date?->toDateString(),
            'currency' => $b->currency,
            'fx_rate' => $b->fx_rate ? (float) $b->fx_rate : null,
            'total' => (float) $b->total,
            'total_base' => (float) $b->total_base,
            'paid_base' => $b->paidBase(),
            'remaining_base' => $b->remainingBase(),
            'status' => $b->status,
            'due_state' => $b->status !== 'paid' && $b->due_date
                ? ($b->due_date->isToday() ? 'today' : ($b->due_date->isPast() ? 'overdue' : 'ok'))
                : 'ok',
            'notes' => $b->notes,
        ];
    }

    // -- helpers ------------------------------------------------------------

    /** Active accounts with balances; banks hidden without view_bank_accounts. */
    protected function visibleAccounts(Request $request)
    {
        return $this->accountRows($request, activeOnly: true);
    }

    /** Same, but includes deactivated accounts (Arka & Banka management page). */
    protected function manageableAccounts(Request $request)
    {
        return $this->accountRows($request, activeOnly: false);
    }

    protected function accountRows(Request $request, bool $activeOnly)
    {
        $q = FinanceAccount::orderBy('id');
        if ($activeOnly) {
            $q->where('is_active', true);
        }
        if (! $request->user()->can('view_bank_accounts')) {
            $q->where('type', 'cash');
        }

        return $q->get()->map(function (FinanceAccount $a) {
            $balance = $a->balance();
            $rate = $a->currency === 'EUR' ? 1.0 : CurrencyRates::rate($a->currency);

            return [
                'id' => $a->id,
                'name' => $a->name,
                'type' => $a->type,
                'currency' => $a->currency,
                'iban' => $a->iban,
                'is_active' => $a->is_active,
                'balance' => $balance,
                'balance_base' => $rate ? round($balance / $rate, 2) : null,
            ];
        })->values();
    }

    protected function cashSummary(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $row = FinancePayment::query()
            ->whereBetween('paid_at', [$from, $to])
            ->whereIn('direction', ['in', 'out'])
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN amount_base ELSE 0 END), 0) as income")
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'out' THEN amount_base ELSE 0 END), 0) as expenses")
            ->first();

        $income = round((float) $row->income, 2);
        $expenses = round((float) $row->expenses, 2);

        return [
            'income' => $income,
            'expenses' => $expenses,
            'net' => round($income - $expenses, 2),
        ];
    }

    protected function percentageChange(float $current, float $previous): ?float
    {
        if (abs($previous) < 0.005) {
            return abs($current) < 0.005 ? 0.0 : null;
        }

        return round((($current - $previous) / abs($previous)) * 100, 1);
    }

    protected function shared(Request $request): array
    {
        return [
            'baseCurrency' => 'EUR',
            'fxRate' => (float) Setting::get('financial.fx_all_per_eur', 0) ?: null,
            'can' => [
                'createPayment' => $request->user()->can('create_payment'),
                'payBills' => $request->user()->can('pay_bills'),
                'transfers' => $request->user()->can('manage_transfers'),
                'bank' => $request->user()->can('view_bank_accounts'),
                'manageBills' => $request->user()->can('manage_bills'),
                'manageSuppliers' => $request->user()->can('manage_suppliers'),
                'manageAccounts' => $request->user()->can('manage_finance_settings'),
            ],
        ];
    }

    protected function paymentRows($payments)
    {
        return $payments->map(fn ($p) => $this->paymentRow($p))->values();
    }

    protected function paymentRow(FinancePayment $p): array
    {
        return [
            'id' => $p->id,
            'direction' => $p->direction,
            'account' => $p->account?->name,
            'counter_account' => $p->counterAccount?->name,
            'amount' => (float) $p->amount,
            'currency' => $p->currency,
            'amount_base' => (float) $p->amount_base,
            'method' => $p->method,
            'source' => $p->source,
            'description' => $p->description,
            'paid_at' => $p->paid_at->toDateTimeString(),
        ];
    }
}
