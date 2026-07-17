<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\BillItem;
use App\Models\FinanceAccount;
use App\Models\FinancePayment;
use App\Models\FiscalDocument;
use App\Models\FolioItem;
use App\Models\InventoryItem;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PosFiscalDocument;
use App\Models\PosOrder;
use App\Models\PosShift;
use App\Models\Reservation;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\Warehouse;
use App\Services\BaseCurrency;
use App\Services\BillDocumentAiExtractor;
use App\Services\CurrencyRates;
use App\Services\GeminiClient;
use App\Services\InventoryLedger;
use App\Services\VatConfiguration;
use App\Tenancy\TenantRule;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Finance module (Phase 1): Paneli + Arka & Banka + Pagesat.
 * The ledger is fed automatically (FinanceLedger); these screens read it and
 * accept the few MANUAL movements: ad-hoc payments and transfers.
 * Bank accounts are visible only with view_bank_accounts — everywhere.
 */
class FinanceController extends Controller
{
    public function __construct(
        private readonly InventoryLedger $inventoryLedger,
        private readonly VatConfiguration $vatConfiguration,
    ) {}

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

        // 14-day cash-flow in the hotel's base currency (transfers move money between our own
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
                'label' => 'Arka mbi limitin e sigurisë ('.BaseCurrency::symbol().number_format($arkaLimit, 0).') — bëj depozitim në bankë',
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
            'latest' => $this->paymentRows(FinancePayment::with($this->paymentRelations())
                ->latest('paid_at')->latest('id')->limit(8)->get()),
        ]));
    }

    /**
     * Unified sales-invoice register. Hotel stays and direct POS sales remain
     * separate source records, but share one tenant-scoped operational view.
     * Room-charge POS orders stay inside the hotel folio to avoid duplicates.
     */
    public function invoices(Request $request): Response
    {
        $filters = $this->salesInvoiceFilters($request);

        $hotelFeed = Reservation::query()
            ->leftJoin('guests', function ($join) {
                $join->on('guests.id', '=', 'reservations.guest_id')
                    ->on('guests.tenant_id', '=', 'reservations.tenant_id');
            })
            ->where('reservations.status', 'checked_out')
            ->select([
                DB::raw("'hotel' as source"),
                'reservations.id as record_id',
                'reservations.updated_at as operational_at',
                'reservations.total_amount as base_total',
                DB::raw('NULL as payment_method'),
                'guests.first_name as client_first',
                'guests.last_name as client_last',
            ])
            ->selectSub(
                FolioItem::query()
                    ->selectRaw("COALESCE(SUM(CASE WHEN type = 'discount' THEN -amount WHEN type = 'room' THEN 0 ELSE amount END), 0)")
                    ->whereColumn('folio_items.reservation_id', 'reservations.id'),
                'extras_total'
            )
            ->selectSub(
                FiscalDocument::query()->select('fiscalized_at')
                    ->whereColumn('fiscal_documents.reservation_id', 'reservations.id')
                    ->latest('fiscal_documents.id')->limit(1),
                'fiscalized_at'
            )
            ->selectSub(
                FiscalDocument::query()->select('status')
                    ->whereColumn('fiscal_documents.reservation_id', 'reservations.id')
                    ->latest('fiscal_documents.id')->limit(1),
                'fiscal_status'
            )
            ->selectSub(
                FiscalDocument::query()->select('fiscal_number')
                    ->whereColumn('fiscal_documents.reservation_id', 'reservations.id')
                    ->latest('fiscal_documents.id')->limit(1),
                'fiscal_number'
            );

        $posFeed = PosOrder::query()
            ->where('pos_orders.status', 'completed')
            ->whereIn('pos_orders.payment_method', ['cash', 'card'])
            ->select([
                DB::raw("'pos' as source"),
                'pos_orders.id as record_id',
                DB::raw('COALESCE(pos_orders.paid_at, pos_orders.updated_at) as operational_at'),
                'pos_orders.total_amount as base_total',
                'pos_orders.payment_method',
                DB::raw('NULL as client_first'),
                DB::raw('NULL as client_last'),
                DB::raw('0 as extras_total'),
            ])
            ->selectSub(
                PosFiscalDocument::query()->select('fiscalized_at')
                    ->whereColumn('pos_fiscal_documents.pos_order_id', 'pos_orders.id')
                    ->latest('pos_fiscal_documents.id')->limit(1),
                'fiscalized_at'
            )
            ->selectSub(
                PosFiscalDocument::query()->select('status')
                    ->whereColumn('pos_fiscal_documents.pos_order_id', 'pos_orders.id')
                    ->latest('pos_fiscal_documents.id')->limit(1),
                'fiscal_status'
            )
            ->selectSub(
                PosFiscalDocument::query()->select('fiscal_number')
                    ->whereColumn('pos_fiscal_documents.pos_order_id', 'pos_orders.id')
                    ->latest('pos_fiscal_documents.id')->limit(1),
                'fiscal_number'
            );

        $feed = match ($filters['source']) {
            'hotel' => $hotelFeed,
            'pos' => $posFeed,
            default => $hotelFeed->unionAll($posFeed),
        };

        $datedFeed = DB::query()->fromSub($feed, 'sales_invoice_sources')
            ->select('*')
            ->selectRaw('COALESCE(fiscalized_at, operational_at) as issued_at');
        $filtered = DB::query()->fromSub($datedFeed, 'sales_invoice_feed');
        if ($filters['date_from']) {
            $filtered->whereDate('issued_at', '>=', $filters['date_from']);
        }
        if ($filters['date_to']) {
            $filtered->whereDate('issued_at', '<=', $filters['date_to']);
        }
        if ($filters['query'] !== '') {
            $like = '%'.$filters['query'].'%';
            $filtered->where(function ($query) use ($like) {
                $query->where('fiscal_number', 'like', $like)
                    ->orWhere('client_first', 'like', $like)
                    ->orWhere('client_last', 'like', $like)
                    ->orWhere('record_id', 'like', $like);
            });
        }

        $statusCounts = (clone $filtered)
            ->selectRaw('COUNT(*) as total_count')
            ->selectRaw("COALESCE(SUM(CASE WHEN fiscal_status = 'fiscalized' THEN 1 ELSE 0 END), 0) as fiscalized_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN fiscal_status = 'failed' THEN 1 ELSE 0 END), 0) as failed_count")
            ->first();

        if ($filters['status'] === 'fiscalized') {
            $filtered->where('fiscal_status', FiscalDocument::STATUS_FISCALIZED);
        } elseif ($filters['status'] === 'failed') {
            $filtered->where('fiscal_status', FiscalDocument::STATUS_FAILED);
        } elseif ($filters['status'] === 'not_fiscalized') {
            $filtered->where(function ($query) {
                $query->whereNull('fiscal_status')
                    ->orWhere('fiscal_status', '!=', FiscalDocument::STATUS_FISCALIZED);
            });
        }

        $summaryRow = (clone $filtered)
            ->selectRaw('COUNT(*) as total_count')
            ->selectRaw('COALESCE(SUM(base_total + extras_total), 0) as total_value')
            ->selectRaw("COALESCE(SUM(CASE WHEN fiscal_status = 'fiscalized' THEN 1 ELSE 0 END), 0) as fiscalized_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN fiscal_status = 'failed' THEN 1 ELSE 0 END), 0) as failed_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN source = 'hotel' THEN 1 ELSE 0 END), 0) as hotel_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN source = 'pos' THEN 1 ELSE 0 END), 0) as pos_count")
            ->first();

        $paginator = $filtered
            ->orderByDesc('issued_at')
            ->orderByDesc('record_id')
            ->paginate($filters['per_page'])
            ->withQueryString();

        $hotelIds = $paginator->getCollection()->where('source', 'hotel')->pluck('record_id');
        $posIds = $paginator->getCollection()->where('source', 'pos')->pluck('record_id');
        $reservations = Reservation::with([
            'guest:id,first_name,last_name,email,document_type,document_number',
            'room.roomType:id,name',
            'folioItems',
            'payments' => fn ($query) => $query->notVoided()->latest('id'),
            'fiscalDocuments' => fn ($query) => $query->latest('id'),
        ])->whereIn('id', $hotelIds)->get()->keyBy('id');
        $orders = PosOrder::with([
            'items.menuItem:id,name',
            'createdBy:id,name',
            'fiscalDocument',
        ])->whereIn('id', $posIds)->get()->keyBy('id');

        $paginator->setCollection($paginator->getCollection()->map(function ($row) use ($request, $reservations, $orders) {
            if ($row->source === 'hotel') {
                return $this->hotelInvoiceRow($reservations->get((int) $row->record_id), $request);
            }

            return $this->posInvoiceRow($orders->get((int) $row->record_id), $request);
        })->filter()->values());

        return Inertia::render('Finance/Invoices', array_merge($this->shared($request), [
            'invoices' => $paginator,
            'filters' => $filters,
            'summary' => [
                'total_count' => (int) ($summaryRow->total_count ?? 0),
                'total_value' => round((float) ($summaryRow->total_value ?? 0), 2),
                'fiscalized_count' => (int) ($summaryRow->fiscalized_count ?? 0),
                'failed_count' => (int) ($summaryRow->failed_count ?? 0),
                'not_fiscalized_count' => max(0, (int) ($summaryRow->total_count ?? 0) - (int) ($summaryRow->fiscalized_count ?? 0)),
                'hotel_count' => (int) ($summaryRow->hotel_count ?? 0),
                'pos_count' => (int) ($summaryRow->pos_count ?? 0),
                'status_counts' => [
                    'all' => (int) ($statusCounts->total_count ?? 0),
                    'fiscalized' => (int) ($statusCounts->fiscalized_count ?? 0),
                    'not_fiscalized' => max(0, (int) ($statusCounts->total_count ?? 0) - (int) ($statusCounts->fiscalized_count ?? 0)),
                    'failed' => (int) ($statusCounts->failed_count ?? 0),
                ],
            ],
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
        $selectedAccount = $accounts->firstWhere('id', $selectedId);
        if (! $selectedAccount) {
            abort(403);
        }
        $selectedUsesBaseCurrency = strtoupper((string) $selectedAccount['currency']) === BaseCurrency::code();

        // Ledger with a running balance, computed oldest-first then shown newest-first.
        $rows = FinancePayment::with($this->paymentRelations())
            ->where(fn ($q) => $q->where('account_id', $selectedId)->orWhere('counter_account_id', $selectedId))
            ->orderBy('paid_at')->orderBy('id')
            ->get();
        $running = 0.0;
        $ledger = $rows->map(function (FinancePayment $p) use (&$running, $selectedId, $selectedUsesBaseCurrency) {
            $amount = $selectedUsesBaseCurrency ? (float) $p->amount_base : (float) $p->amount;
            $delta = match (true) {
                $p->direction === 'in' && $p->account_id === $selectedId => $amount,
                $p->direction === 'out' && $p->account_id === $selectedId => -$amount,
                $p->direction === 'transfer' && $p->account_id === $selectedId => -$amount,
                default => $amount, // transfer INTO this account
            };
            $running = round($running + $delta, 2);

            return array_merge($this->paymentRow($p), ['delta' => $delta, 'balance' => $running]);
        })->reverse()->values();

        $visibleAccountIds = $accounts->where('is_active', true)->pluck('id');
        $todayNet = FinancePayment::query()
            ->whereDate('paid_at', today())
            ->get()
            ->sum(function (FinancePayment $payment) use ($visibleAccountIds) {
                $amount = (float) $payment->amount_base;
                $net = 0.0;

                if ($visibleAccountIds->contains($payment->account_id)) {
                    $net += match ($payment->direction) {
                        'in' => $amount,
                        'out', 'transfer' => -$amount,
                        default => 0.0,
                    };
                }

                if ($payment->direction === 'transfer' && $visibleAccountIds->contains($payment->counter_account_id)) {
                    $net += $amount;
                }

                return $net;
            });

        return Inertia::render('Finance/Accounts', array_merge($this->shared($request), [
            'accounts' => $accounts,
            'selectedId' => $selectedId,
            'ledger' => $ledger,
            'todayNet' => round($todayNet, 2),
            'currencies' => config('lora.tenant_currencies', ['EUR', 'ALL']),
        ]));
    }

    /** New cash box or bank account (owner-only via manage_finance_settings). */
    public function storeAccount(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'type' => ['required', 'in:cash,bank'],
            'currency' => ['required', Rule::in(config('lora.tenant_currencies', ['EUR', 'ALL']))],
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
        if ($account->is_active && $account->currency === BaseCurrency::code()) {
            $lastOfType = ! FinanceAccount::where('type', $account->type)
                ->where('currency', BaseCurrency::code())
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
        $accounts = $this->visibleAccounts($request);
        $filters = $this->paymentFilters($request, $accounts->pluck('id')->all());
        $baseQuery = $this->filteredPaymentsQuery($request, $filters, false);
        $totals = (clone $baseQuery)
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN amount_base ELSE 0 END), 0) as income")
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'out' THEN amount_base ELSE 0 END), 0) as expenses")
            ->first();
        $income = round((float) $totals->income, 2);
        $expenses = round((float) $totals->expenses, 2);
        $summary = [
            'income' => $income,
            'expenses' => $expenses,
            'net' => round($income - $expenses, 2),
            'transfers' => (clone $baseQuery)->where('direction', 'transfer')->count(),
        ];

        $payments = $this->filteredPaymentsQuery($request, $filters)
            ->with($this->paymentRelations())
            ->latest('paid_at')->latest('id')
            ->paginate($filters['per_page'])->withQueryString()
            ->through(fn ($p) => $this->paymentRow($p));

        return Inertia::render('Finance/Payments', array_merge($this->shared($request), [
            'accounts' => $accounts,
            'filters' => $filters,
            'summary' => $summary,
            'payments' => $payments,
        ]));
    }

    public function exportPayments(Request $request): StreamedResponse
    {
        FinanceAccount::ensureDefaults();
        $accounts = $this->visibleAccounts($request);
        $filters = $this->paymentFilters($request, $accounts->pluck('id')->all());
        $query = $this->filteredPaymentsQuery($request, $filters)
            ->with($this->paymentRelations())
            ->latest('paid_at')->latest('id');

        return response()->streamDownload(function () use ($query) {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Data', 'Lloji', 'Përshkrimi', 'Metoda', 'Llogaria', 'Burimi', 'Shuma', 'Monedha'], ';', '"', '');

            $safe = static fn ($value) => is_string($value) && preg_match('/^[=+\-@]/u', $value) ? "'{$value}" : $value;
            foreach ($query->lazy(500) as $payment) {
                $row = $this->paymentRow($payment);
                $account = $row['direction'] === 'transfer'
                    ? $row['account'].' → '.$row['counter_account']
                    : $row['account'];
                fputcsv($output, [
                    $row['paid_at'],
                    match ($row['direction']) {
                        'in' => 'Hyrje', 'out' => 'Dalje', default => 'Transfertë'
                    },
                    $safe($row['description']),
                    $row['method'],
                    $safe($account),
                    $row['source'],
                    number_format($row['amount'], 2, '.', ''),
                    $row['currency'],
                ], ';', '"', '');
            }
            fclose($output);
        }, 'pagesat-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Manual movement: an ad-hoc arkëtim (in) or expense (out). */
    public function storePayment(Request $request): RedirectResponse
    {
        $baseCurrency = BaseCurrency::code();
        $data = $request->validate([
            'direction' => ['required', 'in:in,out'],
            'account_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999'],
            'currency' => ['required', Rule::in(config('lora.tenant_currencies', ['EUR', 'ALL']))],
            'fx_rate' => ['nullable', 'numeric', 'min:0.000001'],
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
        if ($data['currency'] !== $baseCurrency && empty($data['fx_rate'])) {
            throw ValidationException::withMessages([
                'fx_rate' => "Vendos kursin për {$data['currency']} ndaj {$baseCurrency}.",
            ]);
        }
        if ($account['currency'] !== $baseCurrency && $data['currency'] !== $account['currency']) {
            return back()->with('error', "Kjo llogari mban vetëm {$account['currency']}.");
        }

        FinancePayment::create([
            'direction' => $data['direction'],
            'account_id' => $account['id'],
            'amount' => $data['amount'],
            'currency' => $data['currency'],
            'fx_rate' => $data['currency'] === $baseCurrency ? null : $data['fx_rate'],
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
        $fxRate = $from['currency'] === BaseCurrency::code() ? null : BaseCurrency::rate($from['currency']);
        if ($from['currency'] !== BaseCurrency::code() && ! $fxRate) {
            return back()->with('error', "Kursi {$from['currency']}/".BaseCurrency::code().' mungon. Përditëso kurset te Cilësimet → Monedhat.');
        }

        DB::transaction(function () use ($data, $from, $to, $request, $fxRate) {
            FinancePayment::create([
                'direction' => 'transfer',
                'account_id' => $from['id'],
                'counter_account_id' => $to['id'],
                'amount' => $data['amount'],
                'currency' => $from['currency'],
                'fx_rate' => $fxRate,
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
        Warehouse::ensureDefault();
        $filter = $request->input('filter');
        $category = $request->input('category');
        $search = trim((string) $request->input('search', ''));
        $generatedBillId = $this->generatedBillIdFromSearch($search);
        $billId = $request->integer('bill_id') ?: null;
        $today = CarbonImmutable::today();

        // This month's spend per category (paid or not — commitment view),
        // plus the auto OTA commissions which are never ledger rows.
        $monthStart = $today->startOfMonth();
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

        $openBills = Bill::query()
            ->where('status', '!=', 'paid')
            ->withSum('payments as paid_base', 'amount_base')
            ->get(['id', 'total_base', 'due_date']);
        $remainingBase = fn (Bill $bill) => max(0, round((float) $bill->total_base - (float) ($bill->paid_base ?? 0), 2));
        $overdueBills = $openBills->filter(fn (Bill $bill) => $bill->due_date?->isBefore($today));
        $dueSoonBills = $openBills->filter(fn (Bill $bill) => $bill->due_date
            && $bill->due_date->betweenIncluded($today, $today->addDays(7)));
        $monthPayments = FinancePayment::query()
            ->whereNotNull('bill_id')
            ->where('direction', 'out')
            ->where('paid_at', '>=', $monthStart)
            ->get(['bill_id', 'amount_base']);

        $summary = [
            'open_total' => round((float) $openBills->sum($remainingBase), 2),
            'open_count' => $openBills->count(),
            'supplier_count' => Bill::query()->where('status', '!=', 'paid')->distinct()->count('supplier_id'),
            'overdue_total' => round((float) $overdueBills->sum($remainingBase), 2),
            'overdue_count' => $overdueBills->count(),
            'due_soon_total' => round((float) $dueSoonBills->sum($remainingBase), 2),
            'due_soon_count' => $dueSoonBills->count(),
            'month_paid_total' => round((float) $monthPayments->sum('amount_base'), 2),
            'month_paid_count' => $monthPayments->pluck('bill_id')->unique()->count(),
        ];

        $priorities = Bill::with('supplier:id,name')
            ->withSum('payments as paid_base', 'amount_base')
            ->withExists('payments')
            ->where('status', '!=', 'paid')
            ->whereNotNull('due_date')
            ->orderBy('due_date')
            ->limit(4)
            ->get()
            ->map(fn (Bill $bill) => $this->billRow($bill))
            ->values();

        return Inertia::render('Finance/Bills', array_merge($this->shared($request), [
            'accounts' => $this->visibleAccounts($request),
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')
                ->get(['id', 'name', 'nipt', 'category', 'payment_terms_days']),
            'categories' => Bill::categories(),
            'filters' => ['filter' => $filter, 'category' => $category, 'search' => $search, 'bill_id' => $billId],
            'byCategory' => $byCategory,
            'summary' => $summary,
            'priorities' => $priorities,
            'bills' => Bill::with('supplier:id,name')->withSum('payments as paid_base', 'amount_base')
                ->withExists('payments')
                ->withCount('items')
                ->withCount(['items as received_items_count' => fn ($query) => $query->whereNotNull('received_at')])
                ->latest('issue_date')->latest('id')
                ->when($billId, fn ($qq) => $qq->whereKey($billId))
                ->when($filter === 'unpaid', fn ($qq) => $qq->where('status', '!=', 'paid'))
                ->when(in_array($filter, ['due', 'overdue'], true), fn ($qq) => $qq->where('status', '!=', 'paid')->whereDate('due_date', '<', $today->toDateString()))
                ->when($filter === 'paid', fn ($qq) => $qq->where('status', 'paid'))
                ->when($category, fn ($qq) => $qq->where('category', $category))
                ->when($search !== '', fn ($qq) => $qq->where(function ($searchQuery) use ($search, $generatedBillId) {
                    $searchQuery->where('number', 'like', "%{$search}%")
                        ->orWhereHas('supplier', fn ($supplierQuery) => $supplierQuery->where('name', 'like', "%{$search}%"))
                        ->when($generatedBillId, fn ($generatedQuery) => $generatedQuery->orWhere('id', $generatedBillId));
                }))
                ->paginate(25)->withQueryString()->through(fn (Bill $b) => $this->billRow($b)),
        ]));
    }

    public function createBill(Request $request): Response
    {
        Warehouse::ensureDefault();

        return Inertia::render('Finance/BillCreate', array_merge(
            $this->shared($request),
            $this->billFormOptions($request),
        ));
    }

    public function editBill(Request $request, Bill $bill): Response|RedirectResponse
    {
        if ($bill->payments()->exists()) {
            return redirect()->route('finance.bills')
                ->with('error', 'Fatura nuk mund të ndryshohet sepse ka pagesë të regjistruar.');
        }

        Warehouse::ensureDefault();
        $bill->load('items');
        $stockLocked = $bill->items()->whereHas('movements')->exists();

        return Inertia::render('Finance/BillCreate', array_merge(
            $this->shared($request),
            $this->billFormOptions($request, $bill),
            ['bill' => $this->billFormPayload($bill, $stockLocked)],
        ));
    }

    public function showBill(Request $request, Bill $bill): Response
    {
        Warehouse::ensureDefault();
        $bill->load('items');

        return Inertia::render('Finance/BillCreate', array_merge(
            $this->shared($request),
            $this->billFormOptions($request, $bill, includeFullInventoryCatalog: false),
            [
                'bill' => $this->billFormPayload($bill, $bill->items()->whereHas('movements')->exists()),
                'readOnly' => true,
            ],
        ));
    }

    public function analyzeBillDocument(Request $request, BillDocumentAiExtractor $extractor): JsonResponse
    {
        $data = $request->validate([
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
        ]);

        try {
            return response()->json($extractor->extract($data['document']));
        } catch (\RuntimeException $exception) {
            $known = ['ai_not_configured', 'unsupported_file', 'file_too_large', 'no_readable_lines'];
            $code = in_array($exception->getMessage(), $known, true) ? $exception->getMessage() : 'analysis_failed';
            if ($code === 'analysis_failed') {
                report($exception);
            }

            return response()->json(['error_code' => $code], 422);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json(['error_code' => 'analysis_failed'], 422);
        }
    }

    public function storeBill(Request $request): RedirectResponse
    {
        $baseCurrency = BaseCurrency::code();
        $data = $request->validate([
            'supplier_id' => ['required', 'integer', TenantRule::exists('suppliers')],
            'number' => ['nullable', 'string', 'max:60'],
            'category' => ['required', 'string', 'max:60', Rule::in(Bill::categories())],
            'issue_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'currency' => ['required', Rule::in(config('lora.tenant_currencies', ['EUR', 'ALL']))],
            'fx_rate' => ['nullable', 'numeric', 'min:0.000001'],
            'total' => ['required', 'numeric', 'min:0.01', 'max:9999999'],
            'notes' => ['nullable', 'string', 'max:500'],
            'receive_stock' => ['nullable', 'boolean'],
            'items' => ['nullable', 'array', 'max:50'],
            'items.*.inventory_item_id' => ['nullable', 'integer', TenantRule::exists('inventory_items')->where('is_active', true)],
            'items.*.new_item' => ['nullable', 'array'],
            'items.*.new_item.name' => ['nullable', 'string', 'max:150'],
            'items.*.new_item.sku' => ['nullable', 'string', 'max:60'],
            'items.*.new_item.barcode' => ['nullable', 'string', 'max:80'],
            'items.*.new_item.category' => ['nullable', 'string', 'max:80'],
            'items.*.new_item.type' => ['nullable', Rule::in(['product', 'ingredient', 'consumable', 'service'])],
            'items.*.new_item.unit' => ['nullable', Rule::in(['piece', 'kg', 'liter', 'pack'])],
            'items.*.warehouse_id' => ['nullable', 'integer', TenantRule::exists('warehouses')->where('is_active', true)],
            'items.*.quantity' => ['required', 'numeric', 'min:0.0001', 'max:9999999'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0', 'max:9999999'],
        ]);

        if ($data['currency'] !== $baseCurrency && empty($data['fx_rate'])) {
            throw ValidationException::withMessages([
                'fx_rate' => "Vendos kursin për {$data['currency']} ndaj {$baseCurrency}.",
            ]);
        }

        $data['number'] = trim((string) ($data['number'] ?? '')) ?: null;
        if ($data['number'] && Bill::where('supplier_id', $data['supplier_id'])
            ->whereRaw('LOWER(number) = ?', [mb_strtolower($data['number'])])
            ->exists()) {
            throw ValidationException::withMessages([
                'number' => 'Kjo faturë ekziston tashmë për furnitorin e zgjedhur.',
            ]);
        }

        $lines = collect($data['items'] ?? [])->values();
        if ($lines->isNotEmpty()) {
            foreach ($lines as $index => $line) {
                if (! empty($line['inventory_item_id'])) {
                    continue;
                }
                if (! $request->user()->can('manage_inventory')) {
                    throw ValidationException::withMessages([
                        "items.{$index}.inventory_item_id" => 'Nuk ke leje të krijosh artikuj të rinj. Zgjidh një artikull ekzistues.',
                    ]);
                }
                if (trim((string) data_get($line, 'new_item.name')) === '') {
                    throw ValidationException::withMessages([
                        "items.{$index}.inventory_item_id" => 'Zgjidh një artikull ekzistues ose plotëso artikullin e ri.',
                    ]);
                }
            }

            $data['total'] = round((float) $lines->sum(fn ($line) => (float) $line['quantity'] * (float) $line['unit_cost']), 2);
            if ($data['total'] < 0.01) {
                throw ValidationException::withMessages(['total' => 'Totali i artikujve duhet të jetë më i madh se zero.']);
            }
        }

        DB::transaction(function () use ($data, $lines, $request) {
            $bill = Bill::create(collect($data)->except(['items', 'receive_stock'])->all() + ['status' => 'open']);
            if (! $bill->number) {
                $bill->update(['number' => $this->automaticBillNumber($bill)]);
            }

            foreach ($lines as $index => $lineData) {
                $item = ! empty($lineData['inventory_item_id'])
                    ? InventoryItem::findOrFail($lineData['inventory_item_id'])
                    : $this->resolveOrCreateImportedItem($lineData);
                $stockable = $item->type !== 'service';
                if ($stockable && empty($lineData['warehouse_id'])) {
                    throw ValidationException::withMessages([
                        "items.{$index}.warehouse_id" => 'Zgjidh magazinën ku do të hyjë stoku.',
                    ]);
                }

                $line = BillItem::create([
                    'bill_id' => $bill->id,
                    'inventory_item_id' => $item->id,
                    'warehouse_id' => $lineData['warehouse_id'] ?? null,
                    'description' => $item->name,
                    'quantity' => $lineData['quantity'],
                    'unit' => $item->unit,
                    'unit_cost' => $lineData['unit_cost'],
                    'line_total' => round((float) $lineData['quantity'] * (float) $lineData['unit_cost'], 2),
                    'received_at' => $stockable ? null : now(),
                ]);

                if (($data['receive_stock'] ?? false) && $stockable) {
                    $this->inventoryLedger->receiveBillItem($line, $request->user()->id);
                }
            }
        });

        return redirect()->route('finance.bills')->with('success', $lines->isNotEmpty()
            ? 'Fatura dhe rreshtat e inventarit u regjistruan.'
            : 'Fatura e blerjes u regjistrua.');
    }

    public function updateBill(Request $request, Bill $bill): RedirectResponse
    {
        if ($bill->payments()->exists()) {
            return redirect()->route('finance.bills')
                ->with('error', 'Fatura nuk mund të ndryshohet sepse ka pagesë të regjistruar.');
        }

        $stockLocked = $bill->items()->whereHas('movements')->exists();
        $baseCurrency = BaseCurrency::code();
        $rules = [
            'supplier_id' => ['required', 'integer', TenantRule::exists('suppliers')],
            'number' => ['nullable', 'string', 'max:60'],
            'category' => ['required', 'string', 'max:60', Rule::in(Bill::categories())],
            'issue_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];

        if (! $stockLocked) {
            $rules += [
                'currency' => ['required', Rule::in(config('lora.tenant_currencies', ['EUR', 'ALL']))],
                'fx_rate' => ['nullable', 'numeric', 'min:0.000001'],
                'total' => ['required', 'numeric', 'min:0.01', 'max:9999999'],
                'receive_stock' => ['nullable', 'boolean'],
                'items' => ['nullable', 'array', 'max:50'],
                'items.*.inventory_item_id' => ['nullable', 'integer', TenantRule::exists('inventory_items')],
                'items.*.new_item' => ['nullable', 'array'],
                'items.*.new_item.name' => ['nullable', 'string', 'max:150'],
                'items.*.new_item.sku' => ['nullable', 'string', 'max:60'],
                'items.*.new_item.barcode' => ['nullable', 'string', 'max:80'],
                'items.*.new_item.category' => ['nullable', 'string', 'max:80'],
                'items.*.new_item.type' => ['nullable', Rule::in(['product', 'ingredient', 'consumable', 'service'])],
                'items.*.new_item.unit' => ['nullable', Rule::in(['piece', 'kg', 'liter', 'pack'])],
                'items.*.warehouse_id' => ['nullable', 'integer', TenantRule::exists('warehouses')],
                'items.*.quantity' => ['required', 'numeric', 'min:0.0001', 'max:9999999'],
                'items.*.unit_cost' => ['required', 'numeric', 'min:0', 'max:9999999'],
            ];
        }

        $data = $request->validate($rules);
        $data['number'] = trim((string) ($data['number'] ?? ''))
            ?: $this->automaticBillNumber($bill, $data['issue_date'], (int) $data['supplier_id']);
        if (Bill::where('supplier_id', $data['supplier_id'])
            ->where('id', '!=', $bill->id)
            ->whereRaw('LOWER(number) = ?', [mb_strtolower($data['number'])])
            ->exists()) {
            throw ValidationException::withMessages([
                'number' => 'Kjo faturë ekziston tashmë për furnitorin e zgjedhur.',
            ]);
        }

        if ($stockLocked) {
            DB::transaction(function () use ($bill, $data) {
                $lockedBill = Bill::query()->lockForUpdate()->findOrFail($bill->id);
                if ($lockedBill->payments()->exists()) {
                    throw ValidationException::withMessages([
                        'bill' => 'Fatura mori një pagesë ndërkohë dhe nuk mund të ndryshohet.',
                    ]);
                }

                $lockedBill->update($data);
                $lockedBill->load('supplier');
                foreach ($lockedBill->items()->get() as $line) {
                    $line->movements()->where('type', 'purchase')->update([
                        'notes' => 'Bill '.($lockedBill->number ?: '#'.$lockedBill->id)
                            .' · '.($lockedBill->supplier?->name ?? ''),
                        'occurred_at' => $lockedBill->issue_date->startOfDay(),
                    ]);
                }
            });

            return redirect()->route('finance.bills')->with('success', 'Të dhënat e faturës u përditësuan. Rreshtat mbetën të pandryshuar sepse stoku është pranuar.');
        }

        if ($data['currency'] !== $baseCurrency && empty($data['fx_rate'])) {
            throw ValidationException::withMessages([
                'fx_rate' => "Vendos kursin për {$data['currency']} ndaj {$baseCurrency}.",
            ]);
        }

        $lines = collect($data['items'] ?? [])->values();
        if ($lines->isNotEmpty()) {
            foreach ($lines as $index => $line) {
                if (! empty($line['inventory_item_id'])) {
                    continue;
                }
                if (! $request->user()->can('manage_inventory')) {
                    throw ValidationException::withMessages([
                        "items.{$index}.inventory_item_id" => 'Nuk ke leje të krijosh artikuj të rinj. Zgjidh një artikull ekzistues.',
                    ]);
                }
                if (trim((string) data_get($line, 'new_item.name')) === '') {
                    throw ValidationException::withMessages([
                        "items.{$index}.inventory_item_id" => 'Zgjidh një artikull ekzistues ose plotëso artikullin e ri.',
                    ]);
                }
            }

            $data['total'] = round((float) $lines->sum(fn ($line) => (float) $line['quantity'] * (float) $line['unit_cost']), 2);
            if ($data['total'] < 0.01) {
                throw ValidationException::withMessages(['total' => 'Totali i artikujve duhet të jetë më i madh se zero.']);
            }
        }

        DB::transaction(function () use ($bill, $data, $lines, $request) {
            $lockedBill = Bill::query()->lockForUpdate()->findOrFail($bill->id);
            if ($lockedBill->payments()->exists()) {
                throw ValidationException::withMessages([
                    'bill' => 'Fatura mori një pagesë ndërkohë dhe nuk mund të ndryshohet.',
                ]);
            }
            if ($lockedBill->items()->whereHas('movements')->exists()) {
                throw ValidationException::withMessages([
                    'items' => 'Stoku u pranua ndërkohë. Rifresko faqen para se të vazhdosh.',
                ]);
            }

            $lockedBill->update(collect($data)->except(['items', 'receive_stock'])->all() + ['status' => 'open']);
            $lockedBill->items()->delete();

            foreach ($lines as $index => $lineData) {
                $item = ! empty($lineData['inventory_item_id'])
                    ? InventoryItem::findOrFail($lineData['inventory_item_id'])
                    : $this->resolveOrCreateImportedItem($lineData);
                $stockable = $item->type !== 'service';
                if ($stockable && empty($lineData['warehouse_id'])) {
                    throw ValidationException::withMessages([
                        "items.{$index}.warehouse_id" => 'Zgjidh magazinën ku do të hyjë stoku.',
                    ]);
                }

                $line = BillItem::create([
                    'bill_id' => $lockedBill->id,
                    'inventory_item_id' => $item->id,
                    'warehouse_id' => $lineData['warehouse_id'] ?? null,
                    'description' => $item->name,
                    'quantity' => $lineData['quantity'],
                    'unit' => $item->unit,
                    'unit_cost' => $lineData['unit_cost'],
                    'line_total' => round((float) $lineData['quantity'] * (float) $lineData['unit_cost'], 2),
                    'received_at' => $stockable ? null : now(),
                ]);

                if (($data['receive_stock'] ?? false) && $stockable) {
                    $this->inventoryLedger->receiveBillItem($line, $request->user()->id);
                }
            }
        });

        return redirect()->route('finance.bills')->with('success', 'Fatura u përditësua.');
    }

    private function billFormOptions(Request $request, ?Bill $bill = null, bool $includeFullInventoryCatalog = true): array
    {
        $itemIds = $bill?->items->pluck('inventory_item_id')->filter()->values()->all() ?? [];
        $warehouseIds = $bill?->items->pluck('warehouse_id')->filter()->values()->all() ?? [];

        return [
            'suppliers' => Supplier::query()
                ->where(fn (Builder $query) => $query->where('is_active', true)
                    ->when($bill, fn (Builder $q) => $q->orWhere('id', $bill->supplier_id)))
                ->orderBy('name')
                ->get(['id', 'name', 'nipt', 'category', 'payment_terms_days']),
            'categories' => Bill::categories(),
            'inventoryItems' => InventoryItem::query()
                ->when(
                    $includeFullInventoryCatalog,
                    fn (Builder $query) => $query->where(fn (Builder $q) => $q->where('is_active', true)
                        ->when($itemIds !== [], fn (Builder $itemQuery) => $itemQuery->orWhereIn('id', $itemIds))),
                    fn (Builder $query) => $query->whereIn('id', $itemIds),
                )
                ->orderBy('name')
                ->get($includeFullInventoryCatalog
                    ? ['id', 'name', 'sku', 'type', 'unit', 'average_cost']
                    : ['id', 'name', 'sku', 'type', 'unit']),
            'warehouses' => Warehouse::query()
                ->where(fn (Builder $query) => $query->where('is_active', true)
                    ->when($warehouseIds !== [], fn (Builder $q) => $q->orWhereIn('id', $warehouseIds)))
                ->orderByDesc('is_default')->orderBy('name')
                ->get(['id', 'name', 'is_default']),
            'aiConfigured' => app(GeminiClient::class)->configured(),
            'openAiImport' => ! $bill && $request->input('import') === 'ai',
        ];
    }

    private function billFormPayload(Bill $bill, bool $stockLocked): array
    {
        return [
            'id' => $bill->id,
            'supplier_id' => $bill->supplier_id,
            'number' => $bill->number ?: $this->automaticBillNumber($bill),
            'category' => $bill->category,
            'issue_date' => $bill->issue_date->toDateString(),
            'due_date' => $bill->due_date?->toDateString(),
            'currency' => $bill->currency,
            'fx_rate' => $bill->fx_rate ? (float) $bill->fx_rate : null,
            'total' => (float) $bill->total,
            'notes' => $bill->notes,
            'stock_locked' => $stockLocked,
            'items' => $bill->items->map(fn (BillItem $item) => [
                'inventory_item_id' => $item->inventory_item_id,
                'warehouse_id' => $item->warehouse_id,
                'quantity' => (float) $item->quantity,
                'unit_cost' => (float) $item->unit_cost,
            ])->values(),
        ];
    }

    private function automaticBillNumber(Bill $bill, mixed $issueDate = null, ?int $supplierId = null): string
    {
        $year = $issueDate
            ? CarbonImmutable::parse($issueDate)->year
            : ($bill->issue_date?->year ?? CarbonImmutable::today()->year);
        $base = sprintf('BL-%d-%06d', $year, $bill->id);
        $number = $base;
        $suffix = 2;

        while (Bill::query()
            ->where('supplier_id', $supplierId ?? $bill->supplier_id)
            ->where('id', '!=', $bill->id)
            ->whereRaw('LOWER(number) = ?', [mb_strtolower($number)])
            ->exists()) {
            $number = "{$base}-{$suffix}";
            $suffix++;
        }

        return $number;
    }

    private function generatedBillIdFromSearch(string $search): ?int
    {
        if (! preg_match('/^BL-\d{4}-(\d+)(?:-\d+)?$/i', $search, $matches)) {
            return null;
        }

        $bill = Bill::query()
            ->whereKey((int) $matches[1])
            ->whereNull('number')
            ->first();

        return $bill && mb_strtolower($this->automaticBillNumber($bill)) === mb_strtolower($search)
            ? $bill->id
            : null;
    }

    /** Resolve once more at confirmation time so two imports cannot create the same product. */
    private function resolveOrCreateImportedItem(array $lineData): InventoryItem
    {
        $new = $lineData['new_item'] ?? [];
        $name = trim((string) ($new['name'] ?? ''));
        $sku = Str::upper(trim((string) ($new['sku'] ?? '')));
        $barcode = trim((string) ($new['barcode'] ?? ''));

        $items = InventoryItem::query()->lockForUpdate()->get();
        $code = fn (mixed $value) => Str::upper((string) preg_replace('/[^A-Za-z0-9]+/', '', (string) $value));
        $normalize = fn (mixed $value) => trim((string) preg_replace(
            '/[^a-z0-9]+/',
            ' ',
            Str::lower(Str::ascii(trim((string) $value))),
        ));

        $existing = $items->first(function (InventoryItem $item) use ($sku, $barcode, $name, $code, $normalize) {
            return ($barcode !== '' && $code($item->barcode) === $code($barcode))
                || ($sku !== '' && $code($item->sku) === $code($sku))
                || $normalize($item->name) === $normalize($name);
        });
        if ($existing) {
            if (! $existing->is_active) {
                throw ValidationException::withMessages(['items' => "Artikulli {$existing->name} ekziston, por është joaktiv. Aktivizoje para importit."]);
            }

            return $existing;
        }

        return InventoryItem::create([
            'name' => $name,
            'sku' => $this->availableImportedSku($sku !== '' ? $sku : $name),
            'barcode' => $barcode !== '' ? $barcode : null,
            'category' => trim((string) ($new['category'] ?? '')) ?: null,
            'type' => $new['type'] ?? 'product',
            'unit' => $new['unit'] ?? 'piece',
            'average_cost' => (float) ($lineData['unit_cost'] ?? 0),
            'selling_price' => null,
            'sell_in_pos' => false,
            'sell_in_rooms' => false,
            'minimum_stock' => 0,
            'is_active' => true,
        ]);
    }

    private function availableImportedSku(string $source): string
    {
        $base = Str::upper(Str::ascii($source));
        $base = trim((string) preg_replace('/[^A-Z0-9]+/', '-', $base), '-');
        $base = Str::limit($base !== '' ? $base : 'IMPORT', 48, '');
        $candidate = $base;
        $suffix = 1;

        while (InventoryItem::where('sku', $candidate)->exists()) {
            $candidate = Str::limit($base, 52, '').'-'.$suffix++;
        }

        return $candidate;
    }

    public function receiveBill(Request $request, Bill $bill): RedirectResponse
    {
        DB::transaction(function () use ($bill, $request) {
            $lockedBill = Bill::query()->lockForUpdate()->findOrFail($bill->id);
            $pending = $lockedBill->items()->with('item')->whereNull('received_at')->get();
            foreach ($pending as $line) {
                if ($line->item?->type !== 'service') {
                    $this->inventoryLedger->receiveBillItem($line, $request->user()->id);
                }
            }
        });

        return back()->with('success', 'Stoku i faturës u pranua në magazinë.');
    }

    public function storeBillCategory(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
        ]);

        $this->withLockedBillCategories(function (array $categories) use ($data): void {
            $name = trim($data['name']);
            $normalized = mb_strtolower($name);
            $exists = collect($categories)
                ->contains(fn (string $category) => mb_strtolower(trim($category)) === $normalized);

            if ($exists) {
                throw ValidationException::withMessages(['name' => 'Kjo kategori ekziston tashmë.']);
            }

            $categories[] = $name;
            Setting::set('financial.expense_categories', array_values($categories), 'json');
        });

        return back()->with('success', 'Kategoria u shtua.');
    }

    public function updateBillCategory(Request $request, string $category): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
        ]);

        $this->withLockedBillCategories(function (array $categories) use ($category, $data): void {
            $current = $this->resolveBillCategory($category, $categories);
            $currentNormalized = mb_strtolower(trim($current));
            $name = trim($data['name']);
            $normalized = mb_strtolower($name);
            $exists = collect($categories)->contains(function (string $category) use ($normalized, $currentNormalized) {
                $candidate = mb_strtolower(trim($category));

                return $candidate !== $currentNormalized && $candidate === $normalized;
            });

            if ($exists) {
                throw ValidationException::withMessages(['name' => 'Kjo kategori ekziston tashmë.']);
            }

            $updatedCategories = collect($categories)
                ->map(fn (string $item) => $item === $current ? $name : $item)
                ->values()
                ->all();

            Setting::set('financial.expense_categories', $updatedCategories, 'json');
            Supplier::query()->where('category', $current)->update(['category' => $name]);
            Bill::query()->where('category', $current)->update(['category' => $name]);
        });

        return back()->with('success', 'Kategoria u përditësua kudo.');
    }

    public function destroyBillCategory(string $category): RedirectResponse
    {
        $deleted = $this->withLockedBillCategories(function (array $availableCategories) use ($category): bool {
            $current = $this->resolveBillCategory($category, $availableCategories);

            if (Supplier::query()->where('category', $current)->exists() || Bill::query()->where('category', $current)->exists()) {
                return false;
            }

            $categories = collect($availableCategories)
                ->reject(fn (string $item) => $item === $current)
                ->values()
                ->all();

            if ($categories === []) {
                throw ValidationException::withMessages(['category' => 'Duhet të mbetet të paktën një kategori.']);
            }

            Setting::set('financial.expense_categories', $categories, 'json');

            return true;
        });

        if (! $deleted) {
            return back()->with('error', 'Kategoria është në përdorim. Riemërtoje ose ndrysho furnitorët dhe faturat përpara se ta fshish.');
        }

        return back()->with('success', 'Kategoria u fshi.');
    }

    private function withLockedBillCategories(\Closure $callback): mixed
    {
        return DB::transaction(function () use ($callback) {
            $tenantId = app(TenantContext::class)->id();
            abort_if($tenantId === null, 409, 'Mungon konteksti i hotelit.');
            Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();

            return $callback(Bill::categories());
        });
    }

    private function resolveBillCategory(string $category, ?array $categories = null): string
    {
        $normalized = mb_strtolower(trim($category));
        $resolved = collect($categories ?? Bill::categories())
            ->first(fn (string $item) => mb_strtolower(trim($item)) === $normalized);

        abort_if($resolved === null, 404);

        return $resolved;
    }

    /** Pay a bill (fully or partially) from a visible account — atomic. */
    public function payBill(Request $request, Bill $bill): RedirectResponse
    {
        $baseCurrency = BaseCurrency::code();
        $data = $request->validate([
            'account_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', 'in:cash,card,bank'],
        ]);
        $account = $this->visibleAccounts($request)->firstWhere('id', (int) $data['account_id']);
        if (! $account) {
            abort(403);
        }

        $error = DB::transaction(function () use ($data, $bill, $account, $request, $baseCurrency): ?string {
            $lockedBill = Bill::query()->lockForUpdate()->findOrFail($bill->id);
            if ($account['currency'] !== $baseCurrency && strtoupper($lockedBill->currency) !== $account['currency']) {
                return "Kjo llogari mban vetëm {$account['currency']} — fatura është në {$lockedBill->currency}.";
            }

            // Payment rides the locked bill's currency + frozen fx, so an edit
            // cannot change its value while this settlement is being recorded.
            $amountBase = strtoupper($lockedBill->currency) === $baseCurrency
                ? round((float) $data['amount'], 2)
                : round((float) $data['amount'] / (float) $lockedBill->fx_rate, 2);
            $remainingBase = $lockedBill->remainingBase();
            if ($amountBase > $remainingBase + 0.01) {
                return 'Shuma e kalon mbetjen e faturës ('.number_format($remainingBase, 2).' '.$baseCurrency.' mbetje).';
            }

            FinancePayment::create([
                'direction' => 'out',
                'account_id' => $account['id'],
                'amount' => $data['amount'],
                'currency' => $lockedBill->currency,
                'fx_rate' => strtoupper($lockedBill->currency) === $baseCurrency ? null : $lockedBill->fx_rate,
                'method' => $data['method'],
                'source' => 'manual',
                'bill_id' => $lockedBill->id,
                'description' => 'Pagesë bill '.($lockedBill->number ?: '#'.$lockedBill->id).' — '.($lockedBill->supplier?->name ?? ''),
                'paid_at' => now(),
                'created_by' => $request->user()->id,
            ]);
            $lockedBill->refreshStatus();

            return null;
        });
        if ($error) {
            return back()->with('error', $error);
        }

        return back()->with('success', 'Pagesa e faturës u regjistrua.');
    }

    // -- suppliers (Furnitorët) ----------------------------------------------

    public function suppliers(Request $request): Response
    {
        $today = CarbonImmutable::today();
        $remainingBase = static fn (Bill $bill): float => max(
            0,
            round((float) $bill->total_base - (float) ($bill->paid_base ?? 0), 2),
        );

        $suppliers = Supplier::query()
            ->withCount('bills')
            ->withSum([
                'bills as ytd' => fn (Builder $query) => $query->whereYear('issue_date', $today->year),
            ], 'total_base')
            ->with([
                'bills' => fn ($query) => $query
                    ->where('status', '!=', 'paid')
                    ->withSum('payments as paid_base', 'amount_base')
                    ->orderBy('due_date')
                    ->orderBy('id'),
            ])
            ->orderBy('name')
            ->get()
            ->map(function (Supplier $supplier) use ($remainingBase, $today) {
                $openBills = $supplier->bills
                    ->map(function (Bill $bill) use ($remainingBase, $today) {
                        $remaining = $remainingBase($bill);
                        $isOverdue = $bill->due_date?->isBefore($today) && $remaining > 0;

                        return [
                            'id' => $bill->id,
                            'number' => $bill->number,
                            'issue_date' => $bill->issue_date?->toDateString(),
                            'due_date' => $bill->due_date?->toDateString(),
                            'status' => $bill->status,
                            'remaining_base' => $remaining,
                            'is_overdue' => $isOverdue,
                            'overdue_days' => $isOverdue ? $bill->due_date->diffInDays($today) : 0,
                        ];
                    })
                    ->filter(fn (array $bill) => $bill['remaining_base'] > 0)
                    ->values();

                return [
                    'id' => $supplier->id,
                    'name' => $supplier->name,
                    'nipt' => $supplier->nipt,
                    'category' => $supplier->category,
                    'phone' => $supplier->phone,
                    'email' => $supplier->email,
                    'address' => $supplier->address,
                    'payment_terms_days' => $supplier->payment_terms_days,
                    'is_active' => $supplier->is_active,
                    'bills_count' => $supplier->bills_count,
                    'open_balance' => round((float) $openBills->sum('remaining_base'), 2),
                    'overdue_balance' => round((float) $openBills->where('is_overdue', true)->sum('remaining_base'), 2),
                    'ytd' => round((float) ($supplier->ytd ?? 0), 2),
                    'open_bills_count' => $openBills->count(),
                    'open_bills' => $openBills->take(5),
                ];
            })
            ->values();

        $summary = [
            'active_count' => $suppliers->where('is_active', true)->count(),
            'category_count' => $suppliers->where('is_active', true)->pluck('category')->filter()->unique()->count(),
            'open_total' => round((float) $suppliers->sum('open_balance'), 2),
            'open_bill_count' => $suppliers->sum('open_bills_count'),
            'overdue_total' => round((float) $suppliers->sum('overdue_balance'), 2),
            'overdue_supplier_count' => $suppliers->where('overdue_balance', '>', 0)->count(),
            'ytd_total' => round((float) $suppliers->sum('ytd'), 2),
        ];

        return Inertia::render('Finance/Suppliers', array_merge($this->shared($request), [
            'suppliers' => $suppliers,
            'focusSupplierId' => $request->integer('supplier_id') ?: null,
            'summary' => $summary,
            'categories' => Bill::categories(),
            'categoryUsage' => collect(Bill::categories())->mapWithKeys(fn (string $name) => [
                $name => [
                    'suppliers' => $suppliers->where('category', $name)->count(),
                    'bills' => Bill::query()->where('category', $name)->count(),
                ],
            ]),
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
            'category' => ['nullable', 'string', 'max:60', Rule::in(Bill::categories())],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    private function billRow(Bill $b): array
    {
        $paidBase = array_key_exists('paid_base', $b->getAttributes())
            ? round((float) $b->paid_base, 2)
            : $b->paidBase();
        $hasPayments = array_key_exists('payments_exists', $b->getAttributes())
            ? (bool) $b->payments_exists
            : $b->payments()->exists();

        return [
            'id' => $b->id,
            'number' => $b->number,
            'display_number' => $b->number ?: $this->automaticBillNumber($b),
            'supplier' => $b->supplier?->name,
            'supplier_id' => $b->supplier_id,
            'category' => $b->category,
            'issue_date' => $b->issue_date->toDateString(),
            'due_date' => $b->due_date?->toDateString(),
            'currency' => $b->currency,
            'fx_rate' => $b->fx_rate ? (float) $b->fx_rate : null,
            'total' => (float) $b->total,
            'total_base' => (float) $b->total_base,
            'paid_base' => $paidBase,
            'remaining_base' => max(0, round((float) $b->total_base - $paidBase, 2)),
            'status' => $b->status,
            'due_state' => $b->status !== 'paid' && $b->due_date
                ? ($b->due_date->isToday() ? 'today' : ($b->due_date->isPast() ? 'overdue' : 'ok'))
                : 'ok',
            'notes' => $b->notes,
            'items_count' => (int) ($b->items_count ?? 0),
            'received_items_count' => (int) ($b->received_items_count ?? 0),
            'can_edit' => ! $hasPayments,
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
            $rate = BaseCurrency::rate($a->currency);

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

    protected function paymentFilters(Request $request, array $visibleAccountIds): array
    {
        $direction = in_array($request->input('direction'), ['in', 'out', 'transfer'], true)
            ? $request->input('direction')
            : null;
        $source = in_array($request->input('source'), ['auto', 'manual'], true)
            ? $request->input('source')
            : null;
        $method = in_array($request->input('method'), ['cash', 'card', 'bank', 'ota'], true)
            ? $request->input('method')
            : null;
        $accountId = $request->integer('account_id') ?: null;
        $reservationId = $request->integer('reservation_id') ?: null;
        if ($accountId && ! in_array($accountId, $visibleAccountIds, true)) {
            abort(403);
        }

        $parseDate = static function ($value): ?string {
            if (! is_string($value) || ! $value) {
                return null;
            }
            try {
                $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

                return $date && $date->format('Y-m-d') === $value ? $value : null;
            } catch (\Throwable) {
                return null;
            }
        };

        $hasExplicitDates = $request->hasAny(['date_from', 'date_to', 'all_dates']);
        $dateFrom = $hasExplicitDates ? $parseDate($request->input('date_from')) : now()->subDays(29)->toDateString();
        $dateTo = $hasExplicitDates ? $parseDate($request->input('date_to')) : now()->toDateString();
        $perPage = in_array($request->integer('per_page'), [10, 20, 30, 50], true)
            ? $request->integer('per_page')
            : 20;

        return [
            'direction' => $direction,
            'source' => $source,
            'method' => $method,
            'account_id' => $accountId,
            'reservation_id' => $reservationId,
            'query' => mb_substr(trim((string) $request->input('query', '')), 0, 100),
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'all_dates' => $hasExplicitDates && ! $dateFrom && ! $dateTo,
            'per_page' => $perPage,
        ];
    }

    protected function filteredPaymentsQuery(Request $request, array $filters, bool $withDirection = true): Builder
    {
        $query = FinancePayment::query();

        if ($withDirection && $filters['direction']) {
            $query->where('direction', $filters['direction']);
        }
        if ($filters['source']) {
            $query->where('source', $filters['source']);
        }
        if ($filters['method']) {
            $query->where('method', $filters['method']);
        }
        if ($filters['account_id']) {
            $query->where(fn ($q) => $q
                ->where('account_id', $filters['account_id'])
                ->orWhere('counter_account_id', $filters['account_id']));
        }
        if ($filters['reservation_id']) {
            $reservationId = $filters['reservation_id'];
            $query->where(function ($linked) use ($reservationId) {
                $linked
                    ->whereHasMorph('sourceable', [Payment::class], fn ($payment) => $payment
                        ->where('reservation_id', $reservationId))
                    ->orWhereHas('invoice', fn ($invoice) => $invoice
                        ->where('reservation_id', $reservationId));
            });
        }
        if ($filters['date_from']) {
            $query->whereDate('paid_at', '>=', $filters['date_from']);
        }
        if ($filters['date_to']) {
            $query->whereDate('paid_at', '<=', $filters['date_to']);
        }
        if ($filters['query']) {
            $like = '%'.$filters['query'].'%';
            $query->where(fn ($q) => $q
                ->where('description', 'like', $like)
                ->orWhereHas('account', fn ($account) => $account->where('name', 'like', $like))
                ->orWhereHas('counterAccount', fn ($account) => $account->where('name', 'like', $like)));
        }
        if (! $request->user()->can('view_bank_accounts')) {
            $bankIds = FinanceAccount::where('type', 'bank')->pluck('id');
            $query->whereNotIn('account_id', $bankIds)
                ->where(fn ($q) => $q->whereNull('counter_account_id')->orWhereNotIn('counter_account_id', $bankIds));
        }

        return $query;
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

    /** @return array{source:?string,status:?string,query:string,date_from:?string,date_to:?string,per_page:int} */
    protected function salesInvoiceFilters(Request $request): array
    {
        $parseDate = static function ($value): ?string {
            if (! is_string($value) || $value === '') {
                return null;
            }

            try {
                $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);

                return $date && $date->format('Y-m-d') === $value ? $value : null;
            } catch (\Throwable) {
                return null;
            }
        };

        return [
            'source' => in_array($request->input('source'), ['hotel', 'pos'], true) ? $request->input('source') : null,
            'status' => in_array($request->input('status'), ['fiscalized', 'not_fiscalized', 'failed'], true) ? $request->input('status') : null,
            'query' => mb_substr(trim((string) $request->input('query', '')), 0, 100),
            'date_from' => $parseDate($request->input('date_from')),
            'date_to' => $parseDate($request->input('date_to')),
            'per_page' => in_array($request->integer('per_page'), [10, 20, 30, 50], true) ? $request->integer('per_page') : 20,
        ];
    }

    protected function hotelInvoiceRow(?Reservation $reservation, Request $request): ?array
    {
        if (! $reservation) {
            return null;
        }

        /** @var FiscalDocument|null $document */
        $document = $reservation->fiscalDocuments->first();
        $payload = is_array($document?->invoice_payload) ? $document->invoice_payload : [];
        $folioItems = $reservation->folioItems->whereNotIn('type', ['discount', 'room']);
        $discount = $document
            ? (float) ($payload['invoice_discount_value'] ?? 0)
            : (float) $reservation->folioItems->where('type', 'discount')->sum('amount');
        $lines = ! empty($payload['lines'])
            ? $this->normalizeSalesInvoiceLines($payload['lines'])
            : $this->hotelInvoiceLines($reservation, $folioItems);
        $calculatedTotal = round((float) collect($lines)->sum('total') - $discount, 2);
        $status = $document?->status ?: 'pending';
        $paymentMethods = $reservation->payments->pluck('method')->unique()->values();
        $paymentMethod = $document?->payment_method ?: match (true) {
            $paymentMethods->count() > 1 => 'mixed',
            $paymentMethods->count() === 1 => (string) $paymentMethods->first(),
            default => null,
        };

        return [
            'key' => 'hotel:'.$reservation->id,
            'id' => $reservation->id,
            'source' => 'hotel',
            'number' => $document?->fiscal_number ?: 'HOTEL-'.$reservation->id,
            'reference' => 'Rezervimi #'.$reservation->id,
            'issued_at' => ($document?->fiscalized_at ?: $reservation->updated_at)?->toIso8601String(),
            'client' => trim((string) $reservation->guest?->full_name) ?: 'Klient hoteli',
            'client_email' => $reservation->guest?->email,
            'room' => $reservation->room ? trim('Dhoma '.$reservation->room->room_number.' · '.($reservation->room->roomType?->name ?? '')) : null,
            'stay' => [
                'check_in' => $reservation->check_in_date?->toDateString(),
                'check_out' => $reservation->check_out_date?->toDateString(),
                'nights' => max(1, (int) $reservation->nights),
            ],
            'status' => $status,
            'payment_method' => strtolower((string) $paymentMethod),
            'currency' => $document?->currency ?: BaseCurrency::code(),
            'exchange_rate' => $document?->exchange_rate !== null ? (float) $document->exchange_rate : BaseCurrency::rate('ALL'),
            'subtotal' => round((float) collect($lines)->sum('total'), 2),
            'discount' => round($discount, 2),
            'total' => $document ? (float) $document->total : $calculatedTotal,
            'tax_total' => round((float) collect($lines)->sum('tax_amount'), 2),
            'lines' => $lines,
            'fiscal' => $this->salesFiscalMeta($document),
            'detail_href' => $request->user()->can('view_reservations') ? route('reservations.show', $reservation) : null,
            'fiscalize_href' => $status !== FiscalDocument::STATUS_FISCALIZED && $request->user()->can('update_reservations')
                ? route('reservations.fiscalize', $reservation)
                : null,
        ];
    }

    protected function posInvoiceRow(?PosOrder $order, Request $request): ?array
    {
        if (! $order) {
            return null;
        }

        $document = $order->fiscalDocument;
        $payload = is_array($document?->invoice_payload) ? $document->invoice_payload : [];
        $lines = ! empty($payload['lines'])
            ? $this->normalizeSalesInvoiceLines($payload['lines'])
            : $this->normalizeSalesInvoiceLines($order->items->map(fn ($item) => [
                'product_name' => $item->menuItem?->name ?: 'Artikull POS',
                'quantity' => (int) $item->quantity,
                'price' => (float) $item->unit_price,
                'total' => (float) $item->total_price,
                'unit' => 'copë',
                'vat' => $this->vatConfiguration->productRate(),
            ])->all());
        $status = $document?->status ?: 'pending';

        return [
            'key' => 'pos:'.$order->id,
            'id' => $order->id,
            'source' => 'pos',
            'number' => $document?->fiscal_number ?: 'POS-'.$order->id,
            'reference' => 'Porosia POS #'.$order->id,
            'issued_at' => ($document?->fiscalized_at ?: $order->paid_at ?: $order->updated_at)?->toIso8601String(),
            'client' => 'Klient POS',
            'client_email' => null,
            'room' => null,
            'stay' => null,
            'status' => $status,
            'payment_method' => strtolower((string) ($document?->payment_method ?: $order->payment_method)),
            'currency' => $document?->currency ?: BaseCurrency::code(),
            'exchange_rate' => $document?->exchange_rate !== null ? (float) $document->exchange_rate : BaseCurrency::rate('ALL'),
            'subtotal' => round((float) collect($lines)->sum('total'), 2),
            'discount' => 0.0,
            'total' => $document ? (float) $document->total : (float) $order->total_amount,
            'tax_total' => round((float) collect($lines)->sum('tax_amount'), 2),
            'lines' => $lines,
            'operator' => $order->createdBy?->name,
            'fiscal' => $this->salesFiscalMeta($document),
            'detail_href' => $request->user()->can('view_pos_orders') ? route('pos.index', ['order_id' => $order->id]) : null,
            'fiscalize_href' => $status !== PosFiscalDocument::STATUS_FISCALIZED && $request->user()->can('update_pos_orders')
                ? route('pos.fiscalize', $order)
                : null,
        ];
    }

    protected function hotelInvoiceLines(Reservation $reservation, $folioItems): array
    {
        $nights = max(1, (int) $reservation->nights);
        $roomTotal = round((float) $reservation->total_amount, 2);
        $lines = [[
            'name' => 'Akomodim'.($reservation->room ? ' · Dhoma '.$reservation->room->room_number : ''),
            'quantity' => $nights,
            'unit' => 'natë',
            'unit_price' => round($roomTotal / $nights, 2),
            'vat_rate' => $this->vatConfiguration->accommodationRate(),
            'total' => $roomTotal,
        ]];

        foreach ($folioItems as $item) {
            $quantity = max(0.0001, (float) ($item->inventory_quantity ?: 1));
            $total = round((float) $item->amount, 2);
            $lines[] = [
                'name' => $item->description ?: 'Shërbim hoteli',
                'quantity' => $quantity,
                'unit' => $item->inventory_quantity ? 'copë' : 'shërbim',
                'unit_price' => $item->unit_price !== null ? (float) $item->unit_price : round($total / $quantity, 2),
                'vat_rate' => $item->vat_rate !== null ? (float) $item->vat_rate : $this->vatConfiguration->productRate(),
                'total' => $total,
            ];
        }

        return $this->normalizeSalesInvoiceLines($lines);
    }

    /** @param iterable<int, array<string, mixed>> $lines */
    protected function normalizeSalesInvoiceLines(iterable $lines): array
    {
        return collect($lines)->map(function ($line) {
            $quantity = max(0.0001, (float) ($line['quantity'] ?? 1));
            $total = round((float) ($line['total'] ?? (($line['price'] ?? $line['unit_price'] ?? 0) * $quantity)), 2);
            $vatRate = max(0, (float) ($line['vat'] ?? $line['vat_rate'] ?? 0));

            return [
                'name' => (string) ($line['product_name'] ?? $line['name'] ?? $line['description'] ?? 'Artikull'),
                'quantity' => $quantity,
                'unit' => (string) ($line['unit'] ?? 'copë'),
                'unit_price' => round((float) ($line['price'] ?? $line['unit_price'] ?? ($total / $quantity)), 2),
                'vat_rate' => $vatRate,
                'tax_amount' => $vatRate > 0 ? round($total - ($total / (1 + $vatRate / 100)), 2) : 0.0,
                'total' => $total,
            ];
        })->values()->all();
    }

    protected function salesFiscalMeta(FiscalDocument|PosFiscalDocument|null $document): ?array
    {
        if (! $document) {
            return null;
        }

        return [
            'number' => $document->fiscal_number,
            'internal_id' => $document->internal_id,
            'iic' => $document->iic,
            'fic' => $document->fic,
            'environment' => $document->environment,
            'fiscalized_at' => $document->fiscalized_at?->toIso8601String(),
            'verify_url' => $document->verify_url,
            'pdf_url' => $document->pdf_url,
            'last_error' => $document->last_error,
        ];
    }

    protected function shared(Request $request): array
    {
        return [
            'baseCurrency' => BaseCurrency::code(),
            'baseCurrencySymbol' => BaseCurrency::symbol(),
            'currencies' => config('lora.tenant_currencies', ['EUR', 'ALL']),
            'currencyRates' => BaseCurrency::rates(),
            'fxRate' => BaseCurrency::rate('ALL'),
            'fxUpdatedAt' => CurrencyRates::updatedAt(),
            'can' => [
                'createPayment' => $request->user()->can('create_payment'),
                'payBills' => $request->user()->can('pay_bills'),
                'transfers' => $request->user()->can('manage_transfers'),
                'bank' => $request->user()->can('view_bank_accounts'),
                'manageBills' => $request->user()->can('manage_bills'),
                'manageInventory' => $request->user()->can('manage_inventory'),
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
        $source = $p->sourceable;
        $reservationId = match (true) {
            $source instanceof Payment => $source->reservation_id,
            (bool) $p->invoice?->reservation_id => $p->invoice->reservation_id,
            default => null,
        };
        $canViewReservations = request()->user()?->can('view_reservations') ?? false;
        $canViewReports = request()->user()?->can('view_reports') ?? false;
        $bill = $p->bill;
        $supplier = $bill?->supplier;

        return [
            'id' => $p->id,
            'direction' => $p->direction,
            'account_id' => $p->account_id,
            'account' => $p->account?->name,
            'counter_account_id' => $p->counter_account_id,
            'counter_account' => $p->counterAccount?->name,
            'amount' => (float) $p->amount,
            'currency' => $p->currency,
            'amount_base' => (float) $p->amount_base,
            'method' => $p->method,
            'source' => $p->source,
            'description' => $p->description,
            'paid_at' => $p->paid_at->toDateTimeString(),
            'created_by' => $p->createdBy?->name,
            'related' => [
                'reservation' => $reservationId ? [
                    'id' => $reservationId,
                    'label' => 'Rezervimi #'.$reservationId,
                    'href' => $canViewReservations ? route('reservations.show', $reservationId) : null,
                ] : null,
                'bill' => $bill ? [
                    'id' => $bill->id,
                    'label' => 'Fatura '.($bill->number ?: '#'.$bill->id).' · '.$bill->category,
                    'href' => route('finance.bills', ['bill_id' => $bill->id]),
                ] : null,
                'supplier' => $supplier ? [
                    'id' => $supplier->id,
                    'label' => $supplier->name,
                    'href' => route('finance.suppliers', ['supplier_id' => $supplier->id]),
                ] : null,
                'invoice' => $p->invoice ? [
                    'id' => $p->invoice->id,
                    'label' => 'Fatura '.$p->invoice->number,
                    'href' => $reservationId && $canViewReservations ? route('reservations.show', $reservationId) : null,
                ] : null,
                'source' => match (true) {
                    $source instanceof PosShift => [
                        'id' => $source->id,
                        'label' => 'Turni POS #'.$source->id,
                        'href' => $canViewReports ? route('reports.shifts') : null,
                    ],
                    default => null,
                },
            ],
        ];
    }

    /** Relationships required to build stable, ID-based navigation links. */
    protected function paymentRelations(): array
    {
        return [
            'account:id,name',
            'counterAccount:id,name',
            'bill.supplier:id,name',
            'invoice:id,number,reservation_id',
            'sourceable',
            'createdBy:id,name',
        ];
    }
}
