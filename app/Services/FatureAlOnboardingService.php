<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Tenancy\TenantContext;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use RuntimeException;

class FatureAlOnboardingService
{
    private const PROVIDER = 'fature_al';

    public function __construct(
        private readonly TenantContext $context,
        private readonly FatureAlRequestFactory $requests,
    ) {}

    /** @return array<string, mixed> */
    public function state(Tenant $tenant): array
    {
        $integration = $this->integration($tenant);
        $configuration = $integration?->configuration ?? [];
        $onboarding = (array) ($configuration['onboarding'] ?? []);
        $usesCash = (bool) ($onboarding['uses_cash'] ?? true);

        $steps = [
            'company' => filled($onboarding['registered_at'] ?? null),
            'certificate' => filled($onboarding['certificate_uploaded_at'] ?? null),
            'branch' => filled($onboarding['branch_updated_at'] ?? null),
            'device' => ! $usesCash || filled($onboarding['device_created_at'] ?? null),
            'user' => filled($onboarding['user_updated_at'] ?? null),
            'bank' => filled($onboarding['bank_account_created_at'] ?? null),
            'verify' => ($configuration['last_test_status'] ?? null) === 'success',
        ];
        $required = collect($steps)->except('bank');

        return [
            'environment' => ($configuration['environment'] ?? 'sandbox') === 'production' ? 'production' : 'sandbox',
            'status' => $steps['verify'] ? 'ready' : (collect($steps)->contains(true) ? 'in_progress' : 'not_started'),
            'progress' => (int) round($required->filter()->count() / max(1, $required->count()) * 100),
            'has_partner_token' => filled(config('services.fature_al.onboarding_token')),
            'has_api_token' => filled($integration?->credentials['api_token'] ?? null),
            'enabled' => (bool) ($integration?->enabled),
            'uses_cash' => $usesCash,
            'steps' => $steps,
            'company' => [
                'nuis' => $onboarding['nuis'] ?? null,
                'name' => $onboarding['company_name'] ?? null,
            ],
            'branch' => [
                'id' => $onboarding['branch_id'] ?? null,
                'name' => $onboarding['branch_name'] ?? null,
                'business_unit_code' => $onboarding['business_unit_code'] ?? null,
            ],
            'user' => [
                'id' => $onboarding['user_id'] ?? null,
                'operator_code' => $onboarding['operator_code'] ?? null,
            ],
            'device' => ['fiscal_tcr_code' => $onboarding['fiscal_tcr_code'] ?? null],
            'bank' => ['id' => $onboarding['bank_account_id'] ?? null, 'iban' => $onboarding['iban'] ?? null],
            'certificate_expires_at' => $onboarding['certificate_expires_at'] ?? null,
            'last_tested_at' => $configuration['last_tested_at'] ?? null,
            'last_error' => $onboarding['last_error'] ?? null,
        ];
    }

    /** @param array<string, mixed> $data */
    public function registerCompany(Tenant $tenant, array $data): void
    {
        $partnerToken = trim((string) config('services.fature_al.onboarding_token'));
        if ($partnerToken === '') {
            throw new RuntimeException('FATURE_AL_ONBOARDING_TOKEN mungon në konfigurimin e serverit.');
        }

        $environment = $data['environment'] === 'production' ? 'production' : 'sandbox';
        $response = $this->request($partnerToken)->post($this->baseUrl($environment).'/register', [
            'nuis' => $data['nuis'],
            'name' => $data['name'],
            'address' => $data['address'],
            'administrator' => $data['administrator'],
            'phone' => $data['phone'],
            'email' => $data['email'],
            'issuerInVat' => $data['issuer_in_vat'] ?? null,
            'lastNonCashEInvoiceNumber' => $data['last_non_cash_einvoice_number'] ?? null,
        ]);
        $payload = $this->data($response, 'Regjistrimi i kompanisë në fature.al dështoi.');
        $token = (string) data_get($payload, 'user.token');
        $userId = data_get($payload, 'user.id');
        $branchId = data_get($payload, 'branch.id');

        if ($token === '' || blank($userId) || blank($branchId)) {
            throw new RuntimeException('fature.al nuk ktheu token-in, përdoruesin ose degën e krijuar.');
        }

        $this->mutate($tenant, function (TenantIntegration $integration, array &$credentials, array &$configuration) use ($data, $environment, $payload, $token, $userId, $branchId) {
            $credentials['api_token'] = $token;
            $configuration['environment'] = $environment;
            unset($configuration['last_test_status'], $configuration['last_tested_at'], $configuration['account']);
            $configuration['onboarding'] = [
                'registered_at' => now()->toIso8601String(),
                'nuis' => $data['nuis'],
                'company_name' => $data['name'],
                'uses_cash' => (bool) $data['uses_cash'],
                'user_id' => $userId,
                'branch_id' => $branchId,
                'branch_name' => data_get($payload, 'branch.name'),
            ];
            $integration->enabled = false;
        });
    }

    public function uploadCertificate(Tenant $tenant, UploadedFile $certificate, string $password): void
    {
        [$integration, $onboarding] = $this->readyIntegration($tenant, 'Regjistro fillimisht kompaninë.');
        $response = $this->request($integration->credentials['api_token'])
            ->attach('certificate_file', $certificate->getContent(), $certificate->getClientOriginalName())
            ->post($this->baseUrl($integration->configuration['environment'] ?? 'sandbox').'/on-boarding/certificate', [
                'password' => $password,
            ]);
        $payload = $this->data($response, 'Ngarkimi i certifikatës dështoi.');

        $this->mark($tenant, [
            'certificate_uploaded_at' => now()->toIso8601String(),
            'certificate_expires_at' => data_get($payload, 'cert.expiresAt'),
        ]);
    }

    /** @param array<string, mixed> $data */
    public function updateBranch(Tenant $tenant, array $data): void
    {
        [$integration, $onboarding] = $this->readyIntegration($tenant, 'Regjistro fillimisht kompaninë.');
        if (blank($onboarding['certificate_uploaded_at'] ?? null)) {
            throw new RuntimeException('Ngarko dhe verifiko certifikatën përpara konfigurimit të degës.');
        }
        $branchId = $onboarding['branch_id'] ?? null;
        if (blank($branchId)) {
            throw new RuntimeException('ID e degës mungon nga regjistrimi.');
        }

        $payload = $this->data(
            $this->request($integration->credentials['api_token'])->post(
                $this->baseUrl($integration->configuration['environment'] ?? 'sandbox').'/on-boarding/branch/'.rawurlencode((string) $branchId),
                [
                    'name' => $data['name'],
                    'businessUnitCode' => $data['business_unit_code'],
                    'administrator' => $data['administrator'],
                    'address' => $data['address'],
                ],
            ),
            'Konfigurimi i degës dështoi.',
        );

        $this->mark($tenant, [
            'branch_updated_at' => now()->toIso8601String(),
            'branch_name' => data_get($payload, 'branch.name', $data['name']),
            'business_unit_code' => data_get($payload, 'branch.businessUnitCode', $data['business_unit_code']),
        ]);
    }

    /** @param array<string, mixed> $data */
    public function createDevice(Tenant $tenant, array $data): void
    {
        [$integration, $onboarding] = $this->readyIntegration($tenant, 'Regjistro fillimisht kompaninë.');
        if (blank($onboarding['branch_updated_at'] ?? null)) {
            throw new RuntimeException('Konfiguro njësinë e biznesit përpara pajisjes TCR.');
        }
        $payload = $this->data(
            $this->request($integration->credentials['api_token'])->post(
                $this->baseUrl($integration->configuration['environment'] ?? 'sandbox').'/on-boarding/fiscal-device',
                [
                    'branchId' => (int) $onboarding['branch_id'],
                    'name' => $data['name'],
                    'fromDate' => $data['from_date'],
                    'toDate' => $data['to_date'] ?? null,
                ],
            ),
            'Krijimi i pajisjes fiskale dështoi.',
        );
        $tcr = (string) data_get($payload, 'device.fiscalTcrCode');
        if ($tcr === '') {
            throw new RuntimeException('fature.al nuk ktheu kodin fiskal TCR.');
        }

        $this->mark($tenant, ['device_created_at' => now()->toIso8601String(), 'fiscal_tcr_code' => $tcr]);
    }

    /** @param array<string, mixed> $data */
    public function updateUser(Tenant $tenant, array $data): void
    {
        [$integration, $onboarding] = $this->readyIntegration($tenant, 'Regjistro fillimisht kompaninë.');
        if (blank($onboarding['branch_updated_at'] ?? null)) {
            throw new RuntimeException('Konfiguro njësinë e biznesit përpara operatorit.');
        }
        if (($onboarding['uses_cash'] ?? true) && blank($onboarding['device_created_at'] ?? null)) {
            throw new RuntimeException('Krijo pajisjen fiskale TCR përpara operatorit.');
        }
        $userId = $onboarding['user_id'] ?? null;
        if (blank($userId)) {
            throw new RuntimeException('ID e përdoruesit mungon nga regjistrimi.');
        }

        $this->data(
            $this->request($integration->credentials['api_token'])->post(
                $this->baseUrl($integration->configuration['environment'] ?? 'sandbox').'/on-boarding/user/'.rawurlencode((string) $userId),
                [
                    'name' => $data['name'],
                    'branchId' => (string) $onboarding['branch_id'],
                    'operatorCode' => $data['operator_code'],
                    'fiscalTcrCode' => $onboarding['fiscal_tcr_code'] ?? null,
                ],
            ),
            'Konfigurimi i operatorit dështoi.',
        );

        $this->mark($tenant, ['user_updated_at' => now()->toIso8601String(), 'operator_code' => $data['operator_code']]);
    }

    /** @param array<string, mixed> $data */
    public function createBankAccount(Tenant $tenant, array $data): void
    {
        [$integration] = $this->readyIntegration($tenant, 'Regjistro fillimisht kompaninë.');
        $payload = $this->data(
            $this->request($integration->credentials['api_token'])->post(
                $this->baseUrl($integration->configuration['environment'] ?? 'sandbox').'/on-boarding/bank-account',
                [
                    'name' => $data['name'] ?? null,
                    'holder' => $data['holder'] ?? null,
                    'iban' => $data['iban'],
                    'swift' => $data['swift'] ?? null,
                    'currency' => $data['currency'] ?? null,
                    'notes' => $data['notes'] ?? null,
                ],
            ),
            'Krijimi i llogarisë bankare dështoi.',
        );

        $this->mark($tenant, [
            'bank_account_created_at' => now()->toIso8601String(),
            'bank_account_id' => data_get($payload, 'bankAccount.id'),
            'iban' => data_get($payload, 'bankAccount.iban', $data['iban']),
        ]);
    }

    /** @return array{company:string,nipt:string,branch:string,issuer_in_vat:bool|null} */
    public function verify(Tenant $tenant): array
    {
        [$integration, $onboarding] = $this->readyIntegration($tenant, 'Regjistro fillimisht kompaninë.');
        foreach (['certificate_uploaded_at', 'branch_updated_at', 'user_updated_at'] as $required) {
            if (blank($onboarding[$required] ?? null)) {
                throw new RuntimeException('Përfundo certifikatën, njësinë dhe operatorin përpara verifikimit final.');
            }
        }
        if (($onboarding['uses_cash'] ?? true) && blank($onboarding['device_created_at'] ?? null)) {
            throw new RuntimeException('Pajisja TCR mungon për hotelin që pranon pagesa cash.');
        }
        $payload = $this->data(
            $this->request($integration->credentials['api_token'])->get(
                $this->baseUrl($integration->configuration['environment'] ?? 'sandbox').'/account',
            ),
            'Verifikimi i llogarisë Fature.al dështoi.',
        );
        $issuerInVat = filter_var(
            data_get($payload, 'vatConfigs.issuerInVat'),
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE,
        );
        $account = [
            'company' => (string) ($payload['company'] ?? ''),
            'nipt' => (string) ($payload['nipt'] ?? ''),
            'branch' => (string) data_get($payload, 'branch.name', ''),
            'issuer_in_vat' => $issuerInVat,
        ];

        $this->mutate($tenant, function (TenantIntegration $integration, array &$credentials, array &$configuration) use ($account) {
            $configuration['last_test_status'] = 'success';
            $configuration['last_tested_at'] = now()->toIso8601String();
            $configuration['account'] = $account;
            $configuration['onboarding']['verified_at'] = now()->toIso8601String();
            unset($configuration['onboarding']['last_error']);
            $integration->enabled = true;
        });

        return $account;
    }

    public function recordFailure(Tenant $tenant, string $message): void
    {
        $this->mark($tenant, ['last_error' => $message, 'last_failed_at' => now()->toIso8601String()]);
    }

    /** @return array{0:TenantIntegration,1:array<string,mixed>} */
    private function readyIntegration(Tenant $tenant, string $message): array
    {
        $integration = $this->integration($tenant);
        if (! $integration || blank($integration->credentials['api_token'] ?? null)) {
            throw new RuntimeException($message);
        }

        return [$integration, (array) data_get($integration->configuration, 'onboarding', [])];
    }

    private function integration(Tenant $tenant): ?TenantIntegration
    {
        return $this->context->run($tenant, fn () => TenantIntegration::query()->where('provider', self::PROVIDER)->first());
    }

    /** @param callable(TenantIntegration,array<string,mixed>&,array<string,mixed>&):void $callback */
    private function mutate(Tenant $tenant, callable $callback): void
    {
        $this->context->run($tenant, function () use ($callback) {
            $integration = TenantIntegration::query()->firstOrNew(['provider' => self::PROVIDER]);
            $credentials = $integration->credentials ?? [];
            $configuration = $integration->configuration ?? [];
            $callback($integration, $credentials, $configuration);
            $integration->credentials = $credentials;
            $integration->configuration = $configuration;
            $integration->save();
        });
    }

    /** @param array<string,mixed> $values */
    private function mark(Tenant $tenant, array $values): void
    {
        $this->mutate($tenant, function (TenantIntegration $integration, array &$credentials, array &$configuration) use ($values) {
            $configuration['onboarding'] = array_merge((array) ($configuration['onboarding'] ?? []), $values);
        });
    }

    private function request(string $token): PendingRequest
    {
        return $this->requests->make($token);
    }

    private function baseUrl(string $environment): string
    {
        return $environment === 'production' ? 'https://fature.al/api/v1' : 'https://demo.fature.al/api/v1';
    }

    /** @return array<string,mixed> */
    private function data(Response $response, string $fallback): array
    {
        if ($response->status() === 401) {
            throw new RuntimeException('Token-i i Fature.al nuk u pranua.');
        }
        if ($response->status() === 429) {
            throw new RuntimeException('Fature.al kufizoi kërkesat. Provo përsëri pas pak.');
        }
        if (! $response->successful()) {
            $errors = $response->json('errors');
            $detail = is_array($errors) ? collect($errors)->flatten()->filter()->take(3)->implode(' ') : $response->json('message');
            throw new RuntimeException(trim($fallback.' '.(is_string($detail) ? $detail : '')));
        }

        $body = $response->json();
        $ok = ($body['status'] ?? $body['success'] ?? false) === true;
        if (! $ok || ! is_array($body['data'] ?? null)) {
            throw new RuntimeException($fallback.' Përgjigjja ishte e pavlefshme.');
        }

        return $body['data'];
    }
}
