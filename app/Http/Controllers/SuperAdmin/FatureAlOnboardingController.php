<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Services\FatureAlOnboardingService;
use App\Services\TenantOnboardingService;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

class FatureAlOnboardingController extends Controller
{
    public function show(Tenant $tenant, FatureAlOnboardingService $service): Response
    {
        return Inertia::render('SuperAdmin/Onboarding/FatureAl', [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'currency' => $tenant->currency,
            ],
            'fiscalization' => $service->state($tenant),
        ]);
    }

    public function register(Request $request, Tenant $tenant, FatureAlOnboardingService $service): RedirectResponse
    {
        $data = $request->validate([
            'environment' => ['required', Rule::in(['sandbox', 'production'])],
            'nuis' => ['required', 'string', 'regex:/^[A-Z]\d{8}[A-Z]$/'],
            'name' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string', 'max:255'],
            'administrator' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'email' => ['required', 'email', 'max:255'],
            'issuer_in_vat' => ['nullable', 'boolean'],
            'last_non_cash_einvoice_number' => ['nullable', 'string', 'max:50'],
            'uses_cash' => ['required', 'boolean'],
        ]);

        return $this->execute($tenant, $service, 'company', fn () => $service->registerCompany($tenant, $data), 'Kompania u regjistrua në Fature.al.');
    }

    public function certificate(Request $request, Tenant $tenant, FatureAlOnboardingService $service): RedirectResponse
    {
        $data = $request->validate([
            'certificate' => ['required', 'file', 'max:10240', 'extensions:p12,pfx'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        return $this->execute(
            $tenant,
            $service,
            'certificate',
            fn () => $service->uploadCertificate($tenant, $data['certificate'], $data['password']),
            'Certifikata elektronike u verifikua.',
        );
    }

    public function branch(Request $request, Tenant $tenant, FatureAlOnboardingService $service): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'business_unit_code' => ['required', 'string', 'max:100'],
            'administrator' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string', 'max:255'],
        ]);

        return $this->execute($tenant, $service, 'branch', fn () => $service->updateBranch($tenant, $data), 'Njësia e biznesit u konfigurua.');
    }

    public function device(Request $request, Tenant $tenant, FatureAlOnboardingService $service): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'from_date' => ['required', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
        ]);

        return $this->execute($tenant, $service, 'device', fn () => $service->createDevice($tenant, $data), 'Pajisja fiskale TCR u krijua.');
    }

    public function user(Request $request, Tenant $tenant, FatureAlOnboardingService $service): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'operator_code' => ['required', 'string', 'max:100'],
        ]);

        return $this->execute($tenant, $service, 'user', fn () => $service->updateUser($tenant, $data), 'Operatori fiskal u konfigurua.');
    }

    public function bankAccount(Request $request, Tenant $tenant, FatureAlOnboardingService $service): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'holder' => ['nullable', 'string', 'max:255'],
            'iban' => ['required', 'string', 'max:100'],
            'swift' => ['nullable', 'string', 'max:50'],
            'currency' => ['nullable', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->execute($tenant, $service, 'bank', fn () => $service->createBankAccount($tenant, $data), 'Llogaria bankare u shtua.');
    }

    public function verify(
        Request $request,
        Tenant $tenant,
        FatureAlOnboardingService $service,
        TenantOnboardingService $onboarding,
    ): RedirectResponse {
        return $this->execute($tenant, $service, 'verify', function () use ($request, $tenant, $service, $onboarding) {
            $service->verify($tenant);
            $onboarding->updateTask(
                $onboarding->findOrCreate($tenant),
                'integrations',
                'fature_al',
                true,
                $request->user()?->id,
            );
        }, 'Fature.al u verifikua dhe integrimi u aktivizua.');
    }

    private function execute(
        Tenant $tenant,
        FatureAlOnboardingService $service,
        string $step,
        Closure $operation,
        string $success,
    ): RedirectResponse {
        try {
            $operation();
            $this->audit($tenant, 'tenant.fature_al.onboarding.step', ['step' => $step, 'status' => 'success']);

            return back()->with('success', $success);
        } catch (Throwable $exception) {
            $message = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'Hapi i onboarding-ut në Fature.al dështoi. Provo përsëri.';
            $service->recordFailure($tenant, $message);
            $this->audit($tenant, 'tenant.fature_al.onboarding.step', ['step' => $step, 'status' => 'failed']);

            return back()->withErrors(['fature_al' => $message]);
        }
    }

    /** @param array<string,mixed> $properties */
    private function audit(Tenant $tenant, string $action, array $properties): void
    {
        app(TenantContext::class)->run($tenant, fn () => AuditLog::record($action, $tenant, $properties));
    }
}
