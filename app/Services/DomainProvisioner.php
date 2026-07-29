<?php

namespace App\Services;

use App\Models\TenantDomain;
use Throwable;

/**
 * The domain lifecycle engine: pending_dns → provisioning → active/failed.
 * DNS verification is real (the apex A record must contain the platform
 * server's IP); provisioning drives Forge (site + shared root + certificate)
 * and degrades to a clear failed state — never an exception to the panel —
 * when Forge is unreachable or not configured.
 */
class DomainProvisioner
{
    public function __construct(private readonly ForgeClient $forge) {}

    public function verifyDns(TenantDomain $domain): bool
    {
        $serverIp = ForgeClient::serverIp();
        if ($serverIp === '') {
            $this->mark($domain, $domain->status, 'FORGE_SERVER_IP i pakonfiguruar në server — kontakto zhvilluesin.');

            return false;
        }

        $records = $this->aRecords($domain->domain);

        if (! in_array($serverIp, $records, true)) {
            $found = $records === [] ? 'asnjë A record' : implode(', ', $records);
            $this->mark(
                $domain,
                TenantDomain::STATUS_PENDING_DNS,
                "DNS ende s'tregon te serveri: u gjet {$found}, pritej {$serverIp}.",
            );

            return false;
        }

        $domain->forceFill([
            'verified_at' => now(),
            'status' => $domain->status === TenantDomain::STATUS_ACTIVE
                ? TenantDomain::STATUS_ACTIVE
                : TenantDomain::STATUS_PENDING_DNS,
            'status_message' => null,
        ])->save();

        return true;
    }

    public function provision(TenantDomain $domain): bool
    {
        if (! $domain->verified_at) {
            $this->mark($domain, TenantDomain::STATUS_PENDING_DNS, 'Verifiko fillimisht DNS-in.');

            return false;
        }

        if (! $this->forge->configured()) {
            $this->mark(
                $domain,
                TenantDomain::STATUS_PENDING_DNS,
                'Forge API i pakonfiguruar (FORGE_API_TOKEN / FORGE_SERVER_ID) — provizionimi manual derisa të vendosen.',
            );

            return false;
        }

        try {
            $this->mark($domain, TenantDomain::STATUS_PROVISIONING, 'Duke krijuar sitin në server...');
            $site = $this->forge->createSite($domain->domain);
            $siteId = (int) ($site['id'] ?? 0);
            if ($siteId === 0) {
                throw new \RuntimeException('Forge nuk ktheu id të sitit.');
            }

            $this->forge->pointSiteAtAppRoot($siteId);

            $this->mark($domain, TenantDomain::STATUS_PROVISIONING, 'Duke kërkuar certifikatën SSL...');
            $this->forge->requestLetsEncrypt($siteId, [$domain->domain, 'www.'.$domain->domain]);

            return $this->refreshStatus($domain);
        } catch (Throwable $exception) {
            report($exception);
            // Generic message to the panel; the detail lives in the log.
            $this->mark($domain, TenantDomain::STATUS_FAILED, 'Provizionimi dështoi — shiko logs ose provo Rifresko statusin.');

            return false;
        }
    }

    public function refreshStatus(TenantDomain $domain): bool
    {
        if (! $this->forge->configured()) {
            return false;
        }

        try {
            $site = $this->forge->siteByDomain($domain->domain);
            if (! $site) {
                if ($domain->status === TenantDomain::STATUS_PROVISIONING) {
                    $this->mark($domain, TenantDomain::STATUS_FAILED, 'Siti nuk u gjet në server.');
                }

                return false;
            }

            if ($this->forge->hasActiveCertificate((int) $site['id'])) {
                $this->mark($domain, TenantDomain::STATUS_ACTIVE, null);

                return true;
            }

            $this->mark($domain, TenantDomain::STATUS_PROVISIONING, 'Certifikata SSL ende në lëshim — rifresko pas ~1 minute.');

            return false;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    /**
     * The DNS seam — real lookup in production, overridable in tests.
     *
     * @return list<string>
     */
    public function aRecords(string $domain): array
    {
        $records = @dns_get_record($domain, DNS_A) ?: [];

        return array_values(array_filter(array_map(
            static fn (array $record) => (string) ($record['ip'] ?? ''),
            $records,
        )));
    }

    private function mark(TenantDomain $domain, string $status, ?string $message): void
    {
        $domain->forceFill(['status' => $status, 'status_message' => $message])->save();
    }
}
