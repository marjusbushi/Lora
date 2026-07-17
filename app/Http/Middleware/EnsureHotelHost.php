<?php

namespace App\Http\Middleware;

use App\Models\TenantDomain;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The hotel back-office (/pms, /dashboard) may render only on a registered,
 * active hotel domain whose tenant matches the resolved request context.
 * Product hosts without a hotel registration remain control-plane only.
 * A host that is registered as a hotel's OWN TenantDomain stays a hotel
 * host even if it also appears in the product lists (transitional staging).
 */
class EnsureHotelHost
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $host = strtolower($request->getHost());

        $productHost = in_array($host, config('lora.marketing_hosts', []), true)
            || in_array($host, config('lora.dedicated_control_panel_hosts', []), true);

        $domain = TenantDomain::query()
            ->where('domain', $host)
            ->with('tenant')
            ->first();

        if (! $domain) {
            if ($request->user()?->is_super_admin
                && $productHost
                && ($request->isMethod('GET') || $request->isMethod('HEAD'))) {
                return redirect()->away(
                    rtrim((string) config('lora.control_panel_url'), '/').'/super-admin',
                );
            }

            abort(404);
        }

        abort_unless($domain->tenant?->status === 'active', 404);
        abort_unless($this->context->id() === (int) $domain->tenant_id, 404);

        return $next($request);
    }
}
