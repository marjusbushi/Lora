<?php

namespace App\Mcp\Tools;

use App\Models\Setting;
use App\Services\BaseCurrency;
use App\Tenancy\TenantContext;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetHotelContextTool extends LoraTool
{
    protected string $name = 'get-hotel-context';

    protected string $description = 'Get the connected hotel identity, policies, local time settings, currency, and the signed-in staff member permissions.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->user($request);
        $tenant = app(TenantContext::class)->tenant();

        return Response::structured([
            'hotel' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'timezone' => $tenant->timezone,
                'currency' => BaseCurrency::code(),
                'address' => Setting::get('hotel.address'),
                'phone' => Setting::get('hotel.phone'),
                'check_in_time' => Setting::get('hotel.check_in_time'),
                'check_out_time' => Setting::get('hotel.check_out_time'),
                'public_context' => Setting::get('ai.hotel_context'),
            ],
            'staff' => [
                'id' => $user->id,
                'name' => $user->name,
                'roles' => $user->getRoleNames()->values()->all(),
                'permissions' => $user->getAllPermissions()->pluck('name')->values()->all(),
            ],
        ]);
    }
}
