<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\TenantIntegration;
use App\Models\User;
use App\Services\FatureAlClient;
use App\Services\TenantBillingService;
use App\Services\TenantHandoff;
use App\Services\TenantRoleService;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

class TenantController extends Controller
{
    public function index(Request $request, TenantBillingService $billing): Response
    {
        return Inertia::render('SuperAdmin/Tenants/Index', [
            'tenants' => Tenant::query()
                ->with([
                    'domains' => fn ($query) => $query->orderByDesc('is_primary'),
                    'subscription',
                    'moduleEntitlements',
                ])
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
                    'domains' => $tenant->domains->map(fn (TenantDomain $domain) => [
                        'id' => $domain->id,
                        'domain' => $domain->domain,
                        'is_primary' => (bool) $domain->is_primary,
                    ])->values(),
                    'created_at' => $tenant->created_at?->toIso8601String(),
                    'billing' => $billing->summary($tenant),
                    'integrations' => $this->integrationSummaries($tenant),
                ]),
            'currentTenantId' => $request->user()->current_tenant_id,
        ]);
    }

    public function show(Tenant $tenant, TenantBillingService $billing, TenantContext $context): Response
    {
        $tenant->load(['domains' => fn ($query) => $query->orderByDesc('is_primary')]);
        $summary = $billing->summary($tenant);

        $memberPivots = DB::table('tenant_user')->where('tenant_id', $tenant->id)->get()->keyBy('user_id');

        // Roles are per-team (per hotel): read them INSIDE this tenant's context
        // and reset the cached relation first, or Spatie returns another hotel's
        // role for a shared user (lesson #105).
        $members = $context->run($tenant, function () use ($memberPivots) {
            return User::withoutGlobalScopes()
                ->whereIn('id', $memberPivots->keys()->all())
                ->orderBy('name')
                ->get()
                ->map(function (User $user) use ($memberPivots) {
                    $user->unsetRelation('roles');
                    $pivot = $memberPivots[$user->id];

                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'is_owner' => (bool) $pivot->is_owner,
                        'is_active' => (bool) $pivot->is_active,
                        'is_super_admin' => (bool) $user->is_super_admin,
                        'role' => $user->getRoleNames()->first(),
                    ];
                })
                ->values();
        });

        $activity = AuditLog::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('action', 'like', 'tenant.%')
            ->with('causer:id,name')
            ->orderByDesc('id')
            ->limit(15)
            ->get()
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'actor' => $log->causer?->name ?? 'Sistemi',
                'created_at' => $log->created_at?->toIso8601String(),
            ]);

        $mrrCents = ! in_array($summary['status'], ['active', 'trialing'], true)
            ? 0
            : ($summary['billing_cycle'] === 'annual'
                ? (int) round(($summary['annual_cents'] ?? 0) / 12)
                : ($summary['monthly_fixed_cents'] ?? 0));

        return Inertia::render('SuperAdmin/Tenants/Show', [
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'status' => $tenant->status,
                'timezone' => $tenant->timezone,
                'currency' => $tenant->currency,
                'created_at' => $tenant->created_at?->toIso8601String(),
                'primary_domain' => $tenant->domains->firstWhere('is_primary', true)?->domain,
                'domains' => $tenant->domains->map(fn (TenantDomain $domain) => [
                    'id' => $domain->id,
                    'domain' => $domain->domain,
                    'is_primary' => (bool) $domain->is_primary,
                ])->values(),
                'billing' => $summary,
                'mrr_cents' => $mrrCents,
                'integrations' => $this->integrationSummaries($tenant),
            ],
            'members' => $members,
            'activity' => $activity,
            'currentTenantId' => request()->user()->current_tenant_id,
        ]);
    }

    public function store(
        Request $request,
        TenantRoleService $tenantRoles,
        TenantBillingService $billing,
        TenantContext $context,
    ): RedirectResponse {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:80', 'alpha_dash:ascii', Rule::unique('tenants', 'slug')],
            'primary_domain' => ['nullable', 'string', 'max:255'],
            'timezone' => ['required', 'timezone:all'],
            'currency' => ['required', 'string', 'size:3'],
            'owner_name' => ['nullable', 'string', 'max:120', 'required_with:owner_email'],
            'owner_email' => ['nullable', 'email', 'max:255', 'required_with:owner_name'],
        ]);

        $domain = $this->normalizeDomain($data['primary_domain'] ?? null);

        if ($domain && TenantDomain::query()->where('domain', $domain)->exists()) {
            return back()->withErrors(['primary_domain' => 'Ky domain perdoret nga nje hotel tjeter.']);
        }

        $tenant = DB::transaction(function () use ($data, $domain, $request, $tenantRoles, $billing) {
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
                $request->user()->id => ['is_owner' => true, 'is_active' => true],
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

            $tenantRoles->provision($tenant, $request->user());
            $billing->provision($tenant);

            // The hotel's first REAL owner: an existing account is linked
            // (password untouched), a new one is created with a random
            // password (they set their own via "forgot password").
            if (! empty($data['owner_email'])) {
                $owner = User::withoutGlobalScopes()->withTrashed()->firstOrCreate(
                    ['email' => Str::lower(trim($data['owner_email']))],
                    [
                        'name' => $data['owner_name'],
                        'password' => Str::random(40),
                        'current_tenant_id' => $tenant->id,
                    ],
                );

                if ($owner->trashed()) {
                    $owner->restore();
                }

                if (! $owner->current_tenant_id) {
                    $owner->forceFill(['current_tenant_id' => $tenant->id])->save();
                }

                $tenant->users()->syncWithoutDetaching([
                    $owner->id => ['is_owner' => true, 'is_active' => true],
                ]);

                app(TenantContext::class)->run(
                    $tenant,
                    fn () => $owner->unsetRelation('roles')->assignRole('admin'),
                );
            }

            return $tenant;
        });

        $context->run($tenant, fn () => AuditLog::record('tenant.create', $tenant, [
            'tenant_id' => $tenant->id,
            'tenant_name' => $tenant->name,
        ]));

        $ownerNote = isset($owner) && ! $owner->wasRecentlyCreated
            ? ' U lidh llogaria EKZISTUESE '.$owner->email.' si pronar — verifiko që është personi i duhur.'
            : '';

        return back()->with('success', 'Hoteli u krijua.'.$ownerNote.' Tani mund te kalosh ne tenantin e ri.');
    }

    public function updateSubscription(
        Request $request,
        Tenant $tenant,
        TenantBillingService $billing,
        TenantContext $context,
    ): RedirectResponse {
        $moduleCodes = array_keys($billing->catalog());
        $rules = [
            'status' => ['required', Rule::in(['trialing', 'active', 'past_due', 'suspended', 'canceled'])],
            'billing_cycle' => ['required', Rule::in(['monthly', 'annual'])],
            'current_period_ends_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'modules' => ['required', 'array:'.implode(',', $moduleCodes)],
        ];

        foreach ($moduleCodes as $code) {
            $rules["modules.{$code}.enabled"] = ['required', 'boolean'];
            $rules["modules.{$code}.quantity"] = ['required', 'integer', 'min:1', 'max:10000'];
        }

        $data = $request->validate($rules);
        $before = $billing->summary($tenant);
        $billing->update($tenant, $data);
        $tenant->unsetRelation('subscription')->unsetRelation('moduleEntitlements');
        $after = $billing->summary($tenant);

        $context->run($tenant, fn () => AuditLog::record('tenant.subscription.update', $tenant, [
            'before' => $before,
            'after' => $after,
        ]));

        return back()->with('success', "Abonimi i {$tenant->name} u përditësua.");
    }

    public function switch(Request $request, Tenant $tenant, TenantHandoff $handoff): RedirectResponse
    {
        abort_unless($tenant->status === 'active', 422, 'Ky hotel nuk eshte aktiv.');

        $dashboardUrl = $this->tenantDashboardUrl($tenant);
        $targetHost = parse_url($dashboardUrl, PHP_URL_HOST);
        abort_unless(
            is_string($targetHost)
                && $targetHost !== ''
                && $tenant->domains()->where('domain', $targetHost)->exists(),
            422,
            'Hoteli nuk ka domain aktiv.',
        );

        $token = $handoff->issue($request->user(), $tenant, $targetHost);

        return redirect()->away($this->tenantHandoffUrl($dashboardUrl, $token))->withHeaders([
            'Cache-Control' => 'no-store, max-age=0',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    public function updateStatus(Request $request, Tenant $tenant, TenantContext $context): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['active', 'suspended'])],
        ]);

        // A suspended hotel is locked out everywhere: ResolveTenant only ever
        // resolves an ACTIVE tenant, so its domains 404 and it cannot be
        // switched into — no session/data is exposed while suspended.
        $tenant->forceFill(['status' => $data['status']])->save();

        $context->run($tenant, fn () => AuditLog::record('tenant.status', $tenant, [
            'status' => $data['status'],
        ]));

        $verb = $data['status'] === 'suspended' ? 'u pezullua' : 'u aktivizua';

        return back()->with('success', "{$tenant->name} {$verb}.");
    }

    public function updateIntegration(
        Request $request,
        Tenant $tenant,
        string $provider,
        TenantContext $context,
    ): RedirectResponse {
        abort_unless(in_array($provider, ['channex', 'pok', 'fature_al'], true), 404);

        $data = $request->validate(match ($provider) {
            'channex' => [
                'enabled' => ['required', 'boolean'],
                'api_key' => ['nullable', 'string', 'max:255'],
                'webhook_secret' => ['nullable', 'string', 'max:255'],
                // Pa property id feed-i bëhet account-wide (rrezik cross-tenant).
                'property_id' => ['required_if:enabled,true', 'nullable', 'string', 'max:255'],
                'base_url' => ['nullable', 'url', 'max:255'],
            ],
            'pok' => [
                'enabled' => ['required', 'boolean'],
                'key_id' => ['nullable', 'string', 'max:255'],
                'key_secret' => ['nullable', 'string', 'max:255'],
                'merchant_id' => ['nullable', 'string', 'max:255'],
                'production' => ['required', 'boolean'],
            ],
            'fature_al' => [
                'enabled' => ['required', 'boolean'],
                'api_token' => ['nullable', 'string', 'max:2048'],
                'environment' => ['required', Rule::in(['sandbox', 'production'])],
            ],
        });

        $integration = $context->run(
            $tenant,
            fn () => TenantIntegration::query()->firstOrNew(['provider' => $provider]),
        );

        $credentials = $integration->credentials ?? [];
        $configuration = $integration->configuration ?? [];

        // A blank secret field means "keep the stored one" — stored values are
        // never sent back to the browser, so blanks are the normal case.
        $secretKeys = match ($provider) {
            'channex' => ['api_key', 'webhook_secret'],
            'pok' => ['key_id', 'key_secret'],
            'fature_al' => ['api_token'],
        };

        foreach ($secretKeys as $key) {
            if (filled($data[$key] ?? null)) {
                $credentials[$key] = trim($data[$key]);
            }
        }

        // Non-secret config is pre-filled in the form, so a blank submit is a
        // deliberate CLEAR (secrets stay blank-keeps — they are never pre-filled).
        $configurationKeys = match ($provider) {
            'channex' => ['property_id', 'base_url'],
            'pok' => ['merchant_id'],
            'fature_al' => ['environment'],
        };

        foreach ($configurationKeys as $key) {
            if (array_key_exists($key, $data)) {
                if (filled($data[$key])) {
                    $configuration[$key] = $data[$key];
                } else {
                    unset($configuration[$key]);
                }
            }
        }

        if ($provider === 'pok') {
            $configuration['production'] = (bool) $data['production'];
        }

        if ($provider === 'fature_al' && (bool) $data['enabled'] && blank($credentials['api_token'] ?? null)) {
            throw ValidationException::withMessages([
                'api_token' => 'Vendos token-in API para se të aktivizosh fiskalizimin.',
            ]);
        }

        $context->run($tenant, function () use ($integration, $data, $credentials, $configuration) {
            $integration->fill([
                'enabled' => (bool) $data['enabled'],
                'credentials' => $credentials,
                'configuration' => $configuration,
            ])->save();
        });

        $context->run($tenant, fn () => AuditLog::record('tenant.integration.update', $tenant, [
            'provider' => $provider,
            'enabled' => (bool) $data['enabled'],
            // Field NAMES only — never credential values.
            'updated_fields' => array_keys(array_filter($data, fn ($value) => filled($value))),
        ]));

        return back()->with('success', 'Integrimi '.ucfirst($provider)." u ruajt për {$tenant->name}.");
    }

    public function testIntegration(
        Tenant $tenant,
        string $provider,
        TenantContext $context,
    ): RedirectResponse {
        abort_unless($provider === 'fature_al', 404);

        try {
            $context->run($tenant, function () {
                app(FatureAlClient::class)->testConnection();
                $this->recordIntegrationTest('success');
            });

            $context->run($tenant, fn () => AuditLog::record('tenant.integration.test', $tenant, [
                'provider' => 'fature_al',
                'status' => 'success',
            ]));

            return back()->with('success', 'Lidhja test me fature.al funksionon.');
        } catch (Throwable $exception) {
            $message = $exception instanceof RuntimeException
                ? $exception->getMessage()
                : 'Nuk u lidhëm dot me fature.al. Provo përsëri.';

            $context->run($tenant, function () use ($tenant) {
                $this->recordIntegrationTest('failed');
                AuditLog::record('tenant.integration.test', $tenant, [
                    'provider' => 'fature_al',
                    'status' => 'failed',
                ]);
            });

            return back()->with('error', $message);
        }
    }

    public function storeDomain(Request $request, Tenant $tenant, TenantContext $context): RedirectResponse
    {
        $data = $request->validate(['domain' => ['required', 'string', 'max:255']]);

        $domain = $this->normalizeDomain($data['domain']);

        if (! $domain) {
            return back()->withErrors(['domain' => 'Domain i pavlefshëm.']);
        }

        if (TenantDomain::query()->where('domain', $domain)->exists()) {
            return back()->withErrors(['domain' => 'Ky domain përdoret nga një hotel tjetër.']);
        }

        $tenant->domains()->create([
            'domain' => $domain,
            'is_primary' => ! $tenant->domains()->where('is_primary', true)->exists(),
        ]);

        $context->run($tenant, fn () => AuditLog::record('tenant.domain.create', $tenant, ['domain' => $domain]));

        return back()->with('success', "Domain {$domain} u shtua.");
    }

    public function destroyDomain(Tenant $tenant, TenantDomain $domain, TenantContext $context): RedirectResponse
    {
        if ($domain->is_primary) {
            return back()->withErrors(['domain' => 'Cakto fillimisht një domain tjetër si primar.']);
        }

        $name = $domain->domain;
        $domain->delete();

        $context->run($tenant, fn () => AuditLog::record('tenant.domain.delete', $tenant, ['domain' => $name]));

        return back()->with('success', "Domain {$name} u hoq.");
    }

    public function makePrimaryDomain(Tenant $tenant, TenantDomain $domain, TenantContext $context): RedirectResponse
    {
        DB::transaction(function () use ($tenant, $domain) {
            $tenant->domains()->update(['is_primary' => false]);
            $domain->forceFill(['is_primary' => true])->save();
        });

        $context->run($tenant, fn () => AuditLog::record('tenant.domain.primary', $tenant, ['domain' => $domain->domain]));

        return back()->with('success', "{$domain->domain} u caktua si primar.");
    }

    /** Presence + non-secret config only — secret values never leave the server. */
    private function integrationSummaries(Tenant $tenant): array
    {
        $rows = TenantIntegration::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->get()
            ->keyBy('provider');

        $channex = $rows->get('channex');
        $pok = $rows->get('pok');
        $fature = $rows->get('fature_al');

        return [
            'channex' => [
                'enabled' => (bool) ($channex?->enabled),
                'has_api_key' => filled($channex?->credentials['api_key'] ?? null),
                'has_webhook_secret' => filled($channex?->credentials['webhook_secret'] ?? null),
                'property_id' => $channex?->configuration['property_id'] ?? null,
                'base_url' => $channex?->configuration['base_url'] ?? null,
            ],
            'pok' => [
                'enabled' => (bool) ($pok?->enabled),
                'has_key_id' => filled($pok?->credentials['key_id'] ?? null),
                'has_key_secret' => filled($pok?->credentials['key_secret'] ?? null),
                'merchant_id' => $pok?->configuration['merchant_id'] ?? null,
                'production' => (bool) ($pok?->configuration['production'] ?? false),
            ],
            'fature_al' => [
                'enabled' => (bool) ($fature?->enabled),
                'has_api_token' => filled($fature?->credentials['api_token'] ?? null),
                'environment' => ($fature?->configuration['environment'] ?? 'sandbox') === 'production'
                    ? 'production'
                    : 'sandbox',
                'last_tested_at' => $fature?->configuration['last_tested_at'] ?? null,
                'last_test_status' => $fature?->configuration['last_test_status'] ?? null,
            ],
        ];
    }

    private function recordIntegrationTest(string $status): void
    {
        $integration = TenantIntegration::query()->where('provider', 'fature_al')->first();

        if (! $integration) {
            return;
        }

        $configuration = $integration->configuration ?? [];
        $configuration['last_tested_at'] = now()->toIso8601String();
        $configuration['last_test_status'] = $status;

        $integration->forceFill(['configuration' => $configuration])->save();
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

    private function tenantDashboardUrl(Tenant $tenant): string
    {
        $domains = $tenant->domains()->orderByDesc('is_primary')->get();
        $domain = $domains->first(fn (TenantDomain $item) => str_starts_with($item->domain, 'admin.'))?->domain
            ?? $domains->firstWhere('is_primary', true)?->domain
            ?? $domains->first()?->domain;

        if (! $domain) {
            return route('dashboard');
        }

        $local = $domain === 'localhost' || str_ends_with($domain, '.test');

        return ($local ? 'http://' : 'https://').$domain.'/dashboard';
    }

    private function tenantHandoffUrl(string $dashboardUrl, string $token): string
    {
        $scheme = parse_url($dashboardUrl, PHP_URL_SCHEME);
        $host = parse_url($dashboardUrl, PHP_URL_HOST);
        $port = parse_url($dashboardUrl, PHP_URL_PORT);

        $origin = $scheme.'://'.$host.($port ? ':'.$port : '');

        return $origin.'/tenant-handoff?token='.rawurlencode($token);
    }
}
