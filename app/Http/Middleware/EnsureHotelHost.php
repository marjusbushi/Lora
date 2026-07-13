<?php

namespace App\Http\Middleware;

use App\Models\TenantDomain;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The hotel back-office (/pms, /dashboard) must never render on the Lora
 * product hosts (marketing site, dedicated control panel) — a member's
 * session would otherwise pull their hotel's data onto lorapms.com.
 * A host that is registered as a hotel's OWN TenantDomain stays a hotel
 * host even if it also appears in the product lists (transitional staging).
 */
class EnsureHotelHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = strtolower($request->getHost());

        $productHost = in_array($host, config('lora.marketing_hosts', []), true)
            || in_array($host, config('lora.dedicated_control_panel_hosts', []), true);

        if ($productHost && ! TenantDomain::query()->where('domain', $host)->exists()) {
            if ($request->user()?->is_super_admin
                && ($request->isMethod('GET') || $request->isMethod('HEAD'))) {
                return redirect()->away(
                    rtrim((string) config('lora.control_panel_url'), '/').'/super-admin',
                );
            }

            abort(404);
        }

        return $next($request);
    }
}
