<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\CleaningTask;
use App\Models\FinanceAccount;
use App\Models\FinancePayment;
use App\Models\Guest;
use App\Models\InventoryItem;
use App\Models\MaintenanceIssue;
use App\Models\PosOrder;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use App\Services\TenantBillingService;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class GlobalSearchController extends Controller
{
    public function __invoke(Request $request, TenantBillingService $billing): JsonResponse
    {
        $request->merge(['q' => trim((string) $request->query('q'))]);
        $data = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:120'],
            'locale' => ['sometimes', 'string', 'in:sq,en'],
        ]);

        app()->setLocale($data['locale'] ?? app()->getLocale());

        /** @var User $user */
        $user = $request->user();
        $term = trim($data['q']);
        $like = '%'.addcslashes($term, '%_\\').'%';
        $tenant = app(TenantContext::class)->tenant();
        $groups = collect();

        if ($this->allowed($user, 'view_reservations')) {
            $groups->push($this->group('reservations', $this->reservations($term, $like)));
        }

        if ($this->allowed($user, 'view_guests')) {
            $groups->push($this->group('guests', $this->guests($term, $like)));
        }

        if ($this->allowed($user, 'view_rooms')) {
            $groups->push($this->group('rooms', $this->rooms($term, $like)));
        }

        if ($this->allowed($user, 'view_finance') && $billing->enabled(TenantBillingService::FINANCE, $tenant)) {
            $groups->push($this->group('finance', $this->finance($user, $term, $like)));
        }

        if ($this->allowed($user, 'view_housekeeping') && $billing->enabled(TenantBillingService::HOUSEKEEPING, $tenant)) {
            $groups->push($this->group('housekeeping', $this->housekeeping($user, $term, $like)));
        }

        if ($this->allowed($user, 'view_maintenance')) {
            $groups->push($this->group('maintenance', $this->maintenance($term, $like)));
        }

        if ($this->allowed($user, 'view_pos_orders') && $billing->enabled(TenantBillingService::POS, $tenant)) {
            $groups->push($this->group('pos', $this->pos($term, $like)));
        }

        if ($this->allowed($user, 'view_inventory') && $billing->enabled(TenantBillingService::FINANCE, $tenant)) {
            $groups->push($this->group('inventory', $this->inventory($term, $like)));
        }

        return response()->json([
            'query' => $term,
            'groups' => $groups->filter(fn (array $group) => $group['results']->isNotEmpty())
                ->map(fn (array $group) => [
                    'key' => $group['key'],
                    'results' => $group['results']->values(),
                ])->values(),
        ]);
    }

    private function allowed(User $user, string $permission): bool
    {
        return $user->can($permission);
    }

    private function group(string $key, Collection $results): array
    {
        return compact('key', 'results');
    }

    private function reservations(string $term, string $like): Collection
    {
        $nameTokens = $this->nameTokens($term);

        return Reservation::query()
            ->with(['guest:id,first_name,last_name', 'room:id,room_number'])
            ->where(function ($query) use ($term, $like, $nameTokens) {
                if (ctype_digit($term)) {
                    $query->orWhereKey((int) $term);
                }
                $query->orWhere('channel_ref', 'like', $like)
                    ->orWhereHas('guest', fn ($guest) => $this->guestMatches($guest, $like, $nameTokens))
                    ->orWhereHas('room', fn ($room) => $room->where('room_number', 'like', $like));
            })
            ->latest('check_in_date')->limit(5)->get()
            ->map(fn (Reservation $reservation) => $this->result(
                'reservation',
                __('global_search.reservation', ['id' => $reservation->id]).' · '.($reservation->guest?->full_name ?: __('global_search.unnamed')),
                implode(' · ', array_filter([
                    $reservation->room?->room_number ? __('global_search.room', ['number' => $reservation->room->room_number]) : null,
                    $reservation->check_in_date?->format('d.m.Y').' – '.$reservation->check_out_date?->format('d.m.Y'),
                    $reservation->status,
                ])),
                route('reservations.show', $reservation, false),
            ));
    }

    private function guests(string $term, string $like): Collection
    {
        $nameTokens = $this->nameTokens($term);

        return Guest::query()->where(function ($query) use ($term, $like, $nameTokens) {
            if (ctype_digit($term)) {
                $query->orWhereKey((int) $term);
            }
            $query->orWhere(fn ($guest) => $this->guestMatches($guest, $like, $nameTokens));
        })->orderBy('last_name')->limit(5)->get()->map(fn (Guest $guest) => $this->result(
            'guest',
            $guest->full_name,
            implode(' · ', array_filter([$guest->email, $guest->phone])),
            route('guests.show', $guest, false),
        ));
    }

    private function rooms(string $term, string $like): Collection
    {
        return Room::query()->with('roomType:id,name')->where(function ($query) use ($term, $like) {
            if (ctype_digit($term)) {
                $query->orWhereKey((int) $term);
            }
            $query->orWhere('room_number', 'like', $like)
                ->orWhere('status', 'like', $like)
                ->orWhereHas('roomType', fn ($type) => $type->where('name', 'like', $like));
        })->orderBy('room_number')->limit(5)->get()->map(fn (Room $room) => $this->result(
            'room',
            __('global_search.room', ['number' => $room->room_number]),
            implode(' · ', array_filter([$room->roomType?->name, __('global_search.floor', ['number' => $room->floor]), $room->status])),
            route('rooms.index', ['room_id' => $room->id], false),
        ));
    }

    private function finance(User $user, string $term, string $like): Collection
    {
        $nameTokens = $this->nameTokens($term);

        // Search the same hotel-stay source displayed by Finance → Sales invoices.
        // The legacy Invoice model is intentionally excluded because that register
        // does not render it and a result could not be opened from the destination.
        $invoices = Reservation::query()->with(['guest:id,first_name,last_name', 'fiscalDocuments:id,reservation_id,fiscal_number'])
            ->where('status', 'checked_out')->where(function ($query) use ($term, $like, $nameTokens) {
                if (ctype_digit($term)) {
                    $query->orWhereKey((int) $term);
                }
                $query->orWhereHas('guest', fn ($guest) => $this->guestMatches($guest, $like, $nameTokens))
                    ->orWhereHas('fiscalDocuments', fn ($document) => $document->where('fiscal_number', 'like', $like));
            })->latest('updated_at')->limit(4)->get()->map(fn (Reservation $invoice) => $this->result(
                'invoice',
                __('global_search.hotel_invoice', ['id' => $invoice->id]),
                implode(' · ', array_filter([$invoice->guest?->full_name, number_format((float) $invoice->total_amount, 2), $invoice->fiscalDocuments->first()?->fiscal_number])),
                route('finance.invoices', ['source' => 'hotel', 'record_id' => $invoice->id], false),
            ));

        $posInvoices = PosOrder::query()->with('fiscalDocument:id,pos_order_id,fiscal_number')
            ->where('status', 'completed')
            ->whereIn('payment_method', ['cash', 'card'])
            ->where(function ($query) use ($term, $like) {
                if (ctype_digit($term)) {
                    $query->orWhereKey((int) $term);
                }
                $query->orWhereHas('fiscalDocument', fn ($document) => $document->where('fiscal_number', 'like', $like));
            })->latest('updated_at')->limit(4)->get()->map(fn (PosOrder $invoice) => $this->result(
                'invoice',
                __('global_search.pos_invoice', ['id' => $invoice->id]),
                implode(' · ', array_filter([number_format((float) $invoice->total_amount, 2), $invoice->payment_method, $invoice->fiscalDocument?->fiscal_number])),
                route('finance.invoices', ['source' => 'pos', 'record_id' => $invoice->id], false),
            ));

        $bills = Bill::query()->with('supplier:id,name')->where(function ($query) use ($term, $like) {
            if (ctype_digit($term)) {
                $query->orWhereKey((int) $term);
            }
            $query->orWhere('number', 'like', $like)
                ->orWhere('category', 'like', $like)
                ->orWhereHas('supplier', fn ($supplier) => $supplier->where('name', 'like', $like));
        })->latest('issue_date')->limit(3)->get()->map(fn (Bill $bill) => $this->result(
            'bill',
            __('global_search.incoming_bill', ['number' => $bill->number ?: '#'.$bill->id]),
            implode(' · ', array_filter([$bill->supplier?->name, number_format((float) $bill->total, 2).' '.$bill->currency, $bill->status])),
            route('finance.bills.show', $bill, false),
        ));

        $payments = FinancePayment::query()->with(['invoice:id,number', 'bill:id,number'])->where(function ($query) use ($term, $like) {
            if (ctype_digit($term)) {
                $query->orWhereKey((int) $term);
            }
            $query->orWhere('description', 'like', $like)
                ->orWhereHas('invoice', fn ($invoice) => $invoice->where('number', 'like', $like))
                ->orWhereHas('bill', fn ($bill) => $bill->where('number', 'like', $like));
        });

        if (! $user->can('view_bank_accounts')) {
            $bankIds = FinanceAccount::where('type', 'bank')->pluck('id');
            $payments->whereNotIn('account_id', $bankIds)
                ->where(fn ($query) => $query->whereNull('counter_account_id')->orWhereNotIn('counter_account_id', $bankIds));
        }

        $payments = $payments->latest('paid_at')->limit(3)->get()->map(fn (FinancePayment $payment) => $this->result(
            'payment',
            __('global_search.payment', ['id' => $payment->id]),
            implode(' · ', array_filter([number_format((float) $payment->amount, 2).' '.$payment->currency, $payment->method, $payment->description])),
            route('finance.payments', ['payment_id' => $payment->id], false),
        ));

        return $invoices->concat($posInvoices)->concat($bills)->concat($payments)->take(7);
    }

    private function housekeeping(User $user, string $term, string $like): Collection
    {
        $tasks = CleaningTask::query()->with('room:id,room_number')->where(function ($query) use ($term, $like) {
            if (ctype_digit($term)) {
                $query->orWhereKey((int) $term);
            }
            $query->orWhere('type', 'like', $like)->orWhere('status', 'like', $like)
                ->orWhere('notes', 'like', $like)
                ->orWhereHas('room', fn ($room) => $room->where('room_number', 'like', $like));
        });

        if (! $user->can('delete_housekeeping')) {
            $tasks->where(fn ($query) => $query->whereNull('assigned_to')->orWhere('assigned_to', $user->id));
        }

        return $tasks->latest('id')->limit(5)->get()->map(fn (CleaningTask $task) => $this->result(
            'housekeeping',
            __('global_search.cleaning', ['id' => $task->id]).' · '.__('global_search.room', ['number' => $task->room?->room_number ?: '—']),
            implode(' · ', array_filter([$task->type, $task->status, $task->priority])),
            route('housekeeping.clean', $task, false),
        ));
    }

    private function maintenance(string $term, string $like): Collection
    {
        return MaintenanceIssue::query()->with('room:id,room_number')->where(function ($query) use ($term, $like) {
            if (ctype_digit($term)) {
                $query->orWhereKey((int) $term);
            }
            $query->orWhere('title', 'like', $like)->orWhere('description', 'like', $like)
                ->orWhere('asset_code', 'like', $like)
                ->orWhereHas('room', fn ($room) => $room->where('room_number', 'like', $like));
        })->latest('id')->limit(5)->get()->map(fn (MaintenanceIssue $issue) => $this->result(
            'maintenance',
            $issue->title,
            implode(' · ', array_filter([$issue->room?->room_number ? __('global_search.room', ['number' => $issue->room->room_number]) : null, $issue->status, $issue->priority])),
            route('maintenance.index', ['issue_id' => $issue->id], false),
        ));
    }

    private function pos(string $term, string $like): Collection
    {
        return PosOrder::query()->where(function ($query) use ($term, $like) {
            if (ctype_digit($term)) {
                $query->orWhereKey((int) $term)->orWhere('reservation_id', (int) $term);
            }
            $query->orWhere('table_number', 'like', $like)->orWhere('status', 'like', $like)
                ->orWhereHas('items.menuItem', fn ($item) => $item->where('name', 'like', $like));
        })->latest('id')->limit(5)->get()->map(fn (PosOrder $order) => $this->result(
            'pos',
            __('global_search.pos_order', ['id' => $order->id]),
            implode(' · ', array_filter([$order->table_number ? __('global_search.table', ['number' => $order->table_number]) : null, number_format((float) $order->total_amount, 2), $order->status])),
            route('pos.index', ['order_id' => $order->id], false),
        ));
    }

    private function inventory(string $term, string $like): Collection
    {
        return InventoryItem::query()->where(function ($query) use ($term, $like) {
            if (ctype_digit($term)) {
                $query->orWhereKey((int) $term);
            }
            $query->orWhere('name', 'like', $like)->orWhere('sku', 'like', $like)
                ->orWhere('barcode', 'like', $like)->orWhere('category', 'like', $like);
        })->orderBy('name')->limit(5)->get()->map(fn (InventoryItem $item) => $this->result(
            'inventory',
            $item->name,
            implode(' · ', array_filter([$item->sku ? 'SKU '.$item->sku : null, $item->category, __('global_search.stock', ['quantity' => number_format($item->stock(), 2), 'unit' => $item->unit])])),
            route('inventory.items', ['item_id' => $item->id, 'status' => $item->is_active ? 'active' : 'inactive'], false),
        ));
    }

    private function result(string $type, string $title, string $subtitle, string $href): array
    {
        return compact('type', 'title', 'subtitle', 'href');
    }

    private function nameTokens(string $term): array
    {
        return collect(preg_split('/\s+/', trim($term)))
            ->filter()
            ->map(fn (string $token) => '%'.addcslashes($token, '%_\\').'%')
            ->values()
            ->all();
    }

    private function guestMatches($query, string $like, array $nameTokens): void
    {
        $query->where(function ($match) use ($like, $nameTokens) {
            $match->where(function ($name) use ($nameTokens) {
                foreach ($nameTokens as $token) {
                    $name->where(fn ($part) => $part
                        ->where('first_name', 'like', $token)
                        ->orWhere('last_name', 'like', $token));
                }
            })->orWhere('email', 'like', $like)
                ->orWhere('phone', 'like', $like)
                ->orWhere('document_number', 'like', $like);
        });
    }
}
