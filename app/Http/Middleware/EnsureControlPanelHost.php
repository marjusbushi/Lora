<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureControlPanelHost
{
    public function handle(Request $request, Closure $next): Response
    {
        if (in_array(strtolower($request->getHost()), config('lora.control_panel_hosts', []), true)) {
            return $next($request);
        }

        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            abort(404);
        }

        $url = rtrim((string) config('lora.control_panel_url'), '/')
            .'/'.$request->path();

        if ($request->getQueryString()) {
            $url .= '?'.$request->getQueryString();
        }

        return redirect()->away($url);
    }
}
