<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\TenantHandoff;
use App\Services\TenantOnboardingService;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class TenantHandoffController extends Controller
{
    public function __invoke(
        Request $request,
        TenantHandoff $handoff,
        TenantContext $context,
        TenantOnboardingService $onboarding,
    ): Response
    {
        $tenant = $context->tenant();
        if (! $tenant) {
            return $this->reject('Hotel not found.', Response::HTTP_NOT_FOUND);
        }

        $token = (string) $request->query('token', '');
        $userId = $handoff->consume($token, $tenant, $request->getHost());

        if (! $userId) {
            return $this->reject('Ky link hyrjeje eshte i pavlefshem ose ka skaduar.');
        }

        $user = User::withoutGlobalScope('tenant_membership')->find($userId);
        if (! $user?->is_super_admin || $user->email_verified_at === null) {
            return $this->reject('Administratori nuk eshte me aktiv.');
        }

        // Replace any destination-domain session completely. No state from a
        // previously authenticated hotel user is allowed to survive.
        $request->session()->invalidate();
        Auth::guard('web')->login($user);
        $request->session()->put('tenant_id', $tenant->id);

        User::withoutGlobalScope('tenant_membership')->whereKey($user->id)->update([
            'current_tenant_id' => $tenant->id,
        ]);

        AuditLog::record('tenant.switch', $tenant, [
            'super_admin_id' => $user->id,
            'handoff' => true,
        ]);

        $destination = $onboarding->tenantDestination($request->query('redirect'));

        return redirect()->to($destination)->withHeaders([
            'Cache-Control' => 'no-store, max-age=0',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    private function reject(string $message, int $status = Response::HTTP_FORBIDDEN): Response
    {
        return response($message, $status)->withHeaders([
            'Cache-Control' => 'no-store, max-age=0',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
