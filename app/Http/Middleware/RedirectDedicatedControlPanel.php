<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectDedicatedControlPanel
{
    public function handle(Request $request, Closure $next): Response
    {
        $dedicated = in_array(
            strtolower($request->getHost()),
            config('lora.dedicated_control_panel_hosts', []),
            true,
        );

        if ($dedicated) {
            abort_unless($request->user()?->is_super_admin, 403);

            return redirect()->away(
                rtrim((string) config('lora.control_panel_url'), '/').'/super-admin',
            );
        }

        return $next($request);
    }
}
