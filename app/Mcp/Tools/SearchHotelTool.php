<?php

namespace App\Mcp\Tools;

use App\Models\Bill;
use App\Models\CleaningTask;
use App\Models\FinanceAccount;
use App\Models\InventoryItem;
use App\Models\Invoice;
use App\Models\MaintenanceIssue;
use App\Models\PosOrder;
use App\Models\Reservation;
use App\Models\User;
use App\Services\TenantBillingService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class SearchHotelTool extends LoraTool
{
    protected string $name = 'search-hotel';

    protected string $description = 'Read-only universal search across the connected hotel modules. Returns concise operational results and internal links, filtered by tenant, enabled AI data scopes, module entitlements, and the signed-in staff role.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->min(2)->max(120)->description('Name, reference, room, document number, account, task, order, SKU, or other hotel search term.')->required(),
            'module' => $schema->string()->enum([
                'all', 'reservations', 'finance', 'housekeeping', 'maintenance', 'pos', 'inventory',
            ])->default('all'),
            'limit' => $schema->integer()->min(1)->max(20)->default(12),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->user($request);
        abort_unless($this->enabled('universal_search_enabled'), 403);
        $data = $request->validate([
            'query' => ['required', 'string', 'min:2', 'max:120'],
            'module' => ['nullable', 'in:all,reservations,finance,housekeeping,maintenance,pos,inventory'],
            'limit' => ['nullable', 'integer', 'between:1,20'],
        ]);

        $term = trim($data['query']);
        $like = '%'.addcslashes($term, '%_\\').'%';
        $requested = $data['module'] ?? 'all';
        $limit = (int) ($data['limit'] ?? 12);
        $results = collect();
        $searched = [];
        $skipped = [];

        $modules = [
            'reservations' => [
                'enabled' => $this->enabled('reservations_enabled'),
                'allowed' => $this->allowed($user, 'view_reservations'),
                'search' => fn () => $this->reservations($term, $like, $limit),
            ],
            'finance' => [
                'enabled' => $this->enabled('finance_enabled', false)
                    && $this->moduleEnabled(TenantBillingService::FINANCE),
                'allowed' => $this->allowed($user, 'view_finance'),
                'search' => fn () => $this->finance($term, $like, $limit),
            ],
            'housekeeping' => [
                'enabled' => $this->enabled('housekeeping_enabled', false)
                    && $this->moduleEnabled(TenantBillingService::HOUSEKEEPING),
                'allowed' => $this->allowed($user, 'view_housekeeping'),
                'search' => fn () => $this->housekeeping($term, $like, $limit),
            ],
            'maintenance' => [
                'enabled' => $this->enabled('maintenance_enabled', false),
                'allowed' => $this->allowed($user, 'view_maintenance'),
                'search' => fn () => $this->maintenance($term, $like, $limit),
            ],
            'pos' => [
                'enabled' => $this->enabled('pos_enabled', false)
                    && $this->moduleEnabled(TenantBillingService::POS),
                'allowed' => $this->allowed($user, 'view_pos_orders'),
                'search' => fn () => $this->pos($term, $like, $limit),
            ],
            'inventory' => [
                'enabled' => $this->enabled('inventory_enabled', false)
                    && $this->moduleEnabled(TenantBillingService::FINANCE),
                'allowed' => $this->allowed($user, 'view_inventory'),
                'search' => fn () => $this->inventory($term, $like, $limit),
            ],
        ];

        foreach ($modules as $module => $config) {
            if ($requested !== 'all' && $requested !== $module) {
                continue;
            }
            if (! $config['enabled'] || ! $config['allowed']) {
                $skipped[] = $module;

                continue;
            }

            $searched[] = $module;
            $results = $results->concat($config['search']());
        }

        return Response::structured([
            'query' => $term,
            'count' => min($results->count(), $limit),
            'searched_modules' => $searched,
            'skipped_modules' => $skipped,
            'results' => $results->take($limit)->values()->all(),
            'privacy' => 'Operational summaries only. Identity documents and payment credentials are never returned.',
        ]);
    }

    private function allowed(User $user, string $permission): bool
    {
        return $user->is_super_admin || $user->can($permission);
    }

    private function reservations(string $term, string $like, int $limit): Collection
    {
        return Reservation::query()
            ->with(['guest:id,first_name,last_name,email,phone', 'room:id,room_number,room_type_id', 'room.roomType:id,name'])
            ->where(function ($query) use ($term, $like) {
                if (ctype_digit($term)) {
                    $query->orWhereKey((int) $term);
                }
                $query->orWhere('channel_ref', 'like', $like)
                    ->orWhereHas('guest', fn ($guest) => $guest
                        ->where('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('email', 'like', $like)
                        ->orWhere('phone', 'like', $like))
                    ->orWhereHas('room', fn ($room) => $room->where('room_number', 'like', $like));
            })
            ->latest('check_in_date')->limit($limit)->get()
            ->map(fn (Reservation $reservation) => [
                'module' => 'reservations',
                'type' => 'reservation',
                'id' => $reservation->id,
                'title' => 'Rezervimi #'.$reservation->id.' · '.($reservation->guest?->full_name ?: 'Pa emër'),
                'subtitle' => implode(' · ', array_filter([
                    $reservation->room?->room_number ? 'Dhoma '.$reservation->room->room_number : null,
                    $reservation->check_in_date?->format('Y-m-d').' → '.$reservation->check_out_date?->format('Y-m-d'),
                    $reservation->status,
                ])),
                'href' => url('/pms/reservations/'.$reservation->id),
            ]);
    }

    private function finance(string $term, string $like, int $limit): Collection
    {
        $invoices = Invoice::query()->with('guest:id,first_name,last_name')
            ->where(function ($query) use ($term, $like) {
                if (ctype_digit($term)) {
                    $query->orWhereKey((int) $term)->orWhere('reservation_id', (int) $term);
                }
                $query->orWhere('number', 'like', $like)
                    ->orWhere('company_name', 'like', $like)
                    ->orWhere('company_nipt', 'like', $like)
                    ->orWhereHas('guest', fn ($guest) => $guest
                        ->where('first_name', 'like', $like)->orWhere('last_name', 'like', $like));
            })->latest('issue_date')->limit($limit)->get()
            ->map(fn (Invoice $invoice) => [
                'module' => 'finance',
                'type' => 'sales_invoice',
                'id' => $invoice->id,
                'title' => 'Faturë shitjeje '.$invoice->number,
                'subtitle' => implode(' · ', array_filter([
                    $invoice->company_name ?: $invoice->guest?->full_name,
                    number_format((float) $invoice->total, 2).' '.$invoice->currency,
                    $invoice->status,
                ])),
                'href' => url('/pms/finance/invoices?invoice_id='.$invoice->id),
            ]);

        $bills = Bill::query()->with('supplier:id,name')->where(function ($query) use ($term, $like) {
            if (ctype_digit($term)) {
                $query->orWhereKey((int) $term);
            }
            $query->orWhere('number', 'like', $like)
                ->orWhere('category', 'like', $like)
                ->orWhere('notes', 'like', $like)
                ->orWhereHas('supplier', fn ($supplier) => $supplier->where('name', 'like', $like));
        })->latest('issue_date')->limit($limit)->get()->map(fn (Bill $bill) => [
            'module' => 'finance',
            'type' => 'purchase_bill',
            'id' => $bill->id,
            'title' => 'Faturë hyrëse '.($bill->number ?: '#'.$bill->id),
            'subtitle' => implode(' · ', array_filter([
                $bill->supplier?->name,
                number_format((float) $bill->total, 2).' '.$bill->currency,
                $bill->status,
            ])),
            'href' => url('/pms/finance/bills/'.$bill->id),
        ]);

        $accounts = FinanceAccount::query()->where(function ($query) use ($term, $like) {
            if (ctype_digit($term)) {
                $query->orWhereKey((int) $term);
            }
            $query->orWhere('name', 'like', $like)->orWhere('iban', 'like', $like);
        })->orderBy('name')->limit($limit)->get()->map(fn (FinanceAccount $account) => [
            'module' => 'finance',
            'type' => 'account',
            'id' => $account->id,
            'title' => $account->name,
            'subtitle' => strtoupper($account->type).' · '.$account->currency.($account->is_active ? '' : ' · joaktive'),
            'href' => url('/pms/finance/accounts?account_id='.$account->id),
        ]);

        return $invoices->concat($bills)->concat($accounts)->take($limit);
    }

    private function housekeeping(string $term, string $like, int $limit): Collection
    {
        return CleaningTask::query()->with('room:id,room_number')->where(function ($query) use ($term, $like) {
            if (ctype_digit($term)) {
                $query->orWhereKey((int) $term);
            }
            $query->orWhere('type', 'like', $like)
                ->orWhere('status', 'like', $like)
                ->orWhere('priority', 'like', $like)
                ->orWhere('notes', 'like', $like)
                ->orWhereHas('room', fn ($room) => $room->where('room_number', 'like', $like));
        })->latest('id')->limit($limit)->get()->map(fn (CleaningTask $task) => [
            'module' => 'housekeeping',
            'type' => 'cleaning_task',
            'id' => $task->id,
            'title' => 'Pastrimi #'.$task->id.' · Dhoma '.($task->room?->room_number ?: '—'),
            'subtitle' => implode(' · ', array_filter([$task->type, $task->status, $task->priority])),
            'href' => url('/pms/housekeeping?task_id='.$task->id),
        ]);
    }

    private function maintenance(string $term, string $like, int $limit): Collection
    {
        return MaintenanceIssue::query()->with('room:id,room_number')->where(function ($query) use ($term, $like) {
            if (ctype_digit($term)) {
                $query->orWhereKey((int) $term);
            }
            $query->orWhere('title', 'like', $like)
                ->orWhere('description', 'like', $like)
                ->orWhere('category', 'like', $like)
                ->orWhere('asset_name', 'like', $like)
                ->orWhere('asset_code', 'like', $like)
                ->orWhereHas('room', fn ($room) => $room->where('room_number', 'like', $like));
        })->latest('id')->limit($limit)->get()->map(fn (MaintenanceIssue $issue) => [
            'module' => 'maintenance',
            'type' => 'maintenance_issue',
            'id' => $issue->id,
            'title' => $issue->title,
            'subtitle' => implode(' · ', array_filter([
                $issue->room?->room_number ? 'Dhoma '.$issue->room->room_number : null,
                $issue->status,
                $issue->priority,
            ])),
            'href' => url('/pms/maintenance?issue_id='.$issue->id),
        ]);
    }

    private function pos(string $term, string $like, int $limit): Collection
    {
        return PosOrder::query()->where(function ($query) use ($term, $like) {
            if (ctype_digit($term)) {
                $query->orWhereKey((int) $term)->orWhere('reservation_id', (int) $term);
            }
            $query->orWhere('table_number', 'like', $like)
                ->orWhere('status', 'like', $like)
                ->orWhere('payment_method', 'like', $like)
                ->orWhereHas('items.menuItem', fn ($item) => $item->where('name', 'like', $like));
        })->latest('id')->limit($limit)->get()->map(fn (PosOrder $order) => [
            'module' => 'pos',
            'type' => 'pos_order',
            'id' => $order->id,
            'title' => 'Porosia POS #'.$order->id,
            'subtitle' => implode(' · ', array_filter([
                $order->table_number ? 'Tavolina '.$order->table_number : null,
                number_format((float) $order->total_amount, 2),
                $order->status,
            ])),
            'href' => url('/pms/pos?order_id='.$order->id),
        ]);
    }

    private function inventory(string $term, string $like, int $limit): Collection
    {
        return InventoryItem::query()->with('category:id,name')->where(function ($query) use ($term, $like) {
            if (ctype_digit($term)) {
                $query->orWhereKey((int) $term);
            }
            $query->orWhere('name', 'like', $like)
                ->orWhere('sku', 'like', $like)
                ->orWhere('barcode', 'like', $like)
                ->orWhereHas('category', fn ($category) => $category->where('name', 'like', $like));
        })->orderBy('name')->limit($limit)->get()->map(fn (InventoryItem $item) => [
            'module' => 'inventory',
            'type' => 'inventory_item',
            'id' => $item->id,
            'title' => $item->name,
            'subtitle' => implode(' · ', array_filter([
                $item->sku ? 'SKU '.$item->sku : null,
                $item->category?->name,
                'Stok '.number_format($item->stock(), 2).' '.$item->unit,
            ])),
            'href' => url('/pms/inventory/items?item_id='.$item->id),
        ]);
    }
}
