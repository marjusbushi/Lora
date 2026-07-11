<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TenantController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('SuperAdmin/Tenants/Index', [
            'tenants' => Tenant::query()
                ->with(['domains' => fn ($query) => $query->orderByDesc('is_primary')])
                ->withCount('users')
                ->orderBy('name')
                ->get()
                ->map(fn (Tenant $tenant) => [
                    'id' => $tenant->id,
                    'uuid' => $tenant->uuid,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'status' => $tenant->status,
                    'timezone' => $tenant->timezone,
                    'currency' => $tenant->currency,
                    'users_count' => $tenant->users_count,
                    'primary_domain' => $tenant->domains->firstWhere('is_primary', true)?->domain,
                    'domains' => $tenant->domains->pluck('domain'),
                    'created_at' => $tenant->created_at?->toIso8601String(),
                ]),
            'currentTenantId' => app(TenantContext::class)->id(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:80', 'alpha_dash:ascii', Rule::unique('tenants', 'slug')],
            'primary_domain' => ['nullable', 'string', 'max:255'],
            'timezone' => ['required', 'timezone:all'],
            'currency' => ['required', 'string', 'size:3'],
        ]);

        $domain = $this->normalizeDomain($data['primary_domain'] ?? null);

        if ($domain && TenantDomain::query()->where('domain', $domain)->exists()) {
            return back()->withErrors(['primary_domain' => 'Ky domain perdoret nga nje hotel tjeter.']);
        }

        $tenant = DB::transaction(function () use ($data, $domain, $request) {
            $tenant = Tenant::create([
                'uuid' => (string) Str::uuid(),
                'name' => $data['name'],
                'slug' => Str::lower($data['slug']),
                'status' => 'active',
                'timezone' => $data['timezone'],
                'currency' => Str::upper($data['currency']),
            ]);

            if ($domain) {
                $tenant->domains()->create([
                    'domain' => $domain,
                    'is_primary' => true,
                ]);
            }

            $tenant->users()->syncWithoutDetaching([
                $request->user()->id => ['is_owner' => true],
            ]);

            DB::table('settings')->insert([
                [
                    'tenant_id' => $tenant->id,
                    'group' => 'hotel',
                    'key' => 'name',
                    'value' => $tenant->name,
                    'type' => 'text',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'tenant_id' => $tenant->id,
                    'group' => 'hotel',
                    'key' => 'timezone',
                    'value' => $tenant->timezone,
                    'type' => 'text',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'tenant_id' => $tenant->id,
                    'group' => 'hotel',
                    'key' => 'currency',
                    'value' => $tenant->currency,
                    'type' => 'text',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);

            return $tenant;
        });

        AuditLog::record('tenant.create', null, [
            'tenant_id' => $tenant->id,
            'tenant_name' => $tenant->name,
        ]);

        return back()->with('success', 'Hoteli u krijua. Tani mund te kalosh ne tenantin e ri.');
    }

    public function switch(Request $request, Tenant $tenant): RedirectResponse
    {
        abort_unless($tenant->status === 'active', 422, 'Ky hotel nuk eshte aktiv.');

        $request->session()->put('tenant_id', $tenant->id);
        $request->user()->withoutGlobalScopes()->whereKey($request->user()->id)->update([
            'current_tenant_id' => $tenant->id,
        ]);

        return redirect()->route('dashboard')->with('success', "Kalove te {$tenant->name}.");
    }

    private function normalizeDomain(?string $domain): ?string
    {
        if (! $domain) {
            return null;
        }

        $value = Str::lower(trim($domain));
        $host = parse_url(str_contains($value, '://') ? $value : 'https://'.$value, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : null;
    }
}
