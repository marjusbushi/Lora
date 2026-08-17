<?php

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

/*
|--------------------------------------------------------------------------
| Kanalet private per-tenant (task MHQ #52 — themeli realtime)
|--------------------------------------------------------------------------
| Autorizimi është FAIL-CLOSED dhe i dyfishtë: (1) tenant-i i kanalit DUHET
| të përputhet me tenant-in e zgjidhur nga sesioni/hosti (ResolveTenant e
| vendos në /broadcasting/auth — hoteli A s'dëgjon dot kurrë hotelin B, as
| me id të falsifikuar në emrin e kanalit); (2) useri duhet të ketë lejen e
| pamjes përkatëse — të njëjtat permission si faqet që ushqehen nga kanali.
*/

Broadcast::channel('tenant.{tenantId}.messages', function ($user, int $tenantId) {
    return app(TenantContext::class)->tenant()?->id === $tenantId
        && $user->can('view_reservations');
});

Broadcast::channel('tenant.{tenantId}.reservations', function ($user, int $tenantId) {
    return app(TenantContext::class)->tenant()?->id === $tenantId
        && $user->can('view_reservations');
});

Broadcast::channel('tenant.{tenantId}.pos', function ($user, int $tenantId) {
    return app(TenantContext::class)->tenant()?->id === $tenantId
        && $user->can('view_pos_orders');
});
