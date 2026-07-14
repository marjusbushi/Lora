<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MenuItemInventory extends TenantModel
{
    protected $table = 'menu_item_inventory';

    protected $fillable = ['menu_item_id', 'inventory_item_id', 'quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4'];
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
