<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Trashëgimi (vendimi A i Marjusit, 2026-08-16): kur Mirëmbajtja dhe Mesazhet
// bëhen module me pagesë, hotelet EKZISTUESE s'humbin asgjë dhe s'paguajnë
// asgjë — i marrin FALAS me çmim të NGRIRË 0 € (rregulli i ngrirjes garanton
// që katalogu i ri s'i prek kurrë). Mesazhet trashëgohen VETËM ku ekziston
// Channel Manager aktiv (pa të, inbox-i s'ka punuar kurrë). Katalogu i ri
// (900c/1900c) vlen vetëm për aktivizime të reja.
return new class extends Migration
{
    private const GRANDFATHERED = ['maintenance', 'messages'];

    public function up(): void
    {
        $now = now();
        $tenants = DB::table('tenants')->get(['id', 'metadata']);

        foreach ($tenants as $tenant) {
            $hasChannelManager = DB::table('tenant_module_entitlements')
                ->where('tenant_id', $tenant->id)
                ->where('module_code', 'channel_manager')
                ->where('enabled', true)
                ->exists();

            $grants = ['maintenance' => true, 'messages' => $hasChannelManager];

            foreach ($grants as $code => $granted) {
                if (! $granted) {
                    continue;
                }

                $definition = config("lora_modules.modules.{$code}");
                if (! is_array($definition)) {
                    continue;
                }

                // updateOrInsert = idempotente; nëse rreshti ekziston tashmë
                // (ri-ekzekutim), s'krijohet dublikatë — unique (tenant, code).
                DB::table('tenant_module_entitlements')->updateOrInsert(
                    ['tenant_id' => $tenant->id, 'module_code' => $code],
                    [
                        'enabled' => true,
                        'quantity' => 1,
                        'unit_price_cents' => 0,
                        'percentage_bps' => null,
                        'pricing_snapshot' => json_encode(array_replace($definition, ['unit_price_cents' => 0])),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                );
            }

            // Rifresko snapshot-in e aksesit VETËM për çelësat e rinj — pa
            // prekur statusin/fushat e tjera të billing_access.
            $metadata = json_decode($tenant->metadata ?? '[]', true) ?: [];
            if (is_array($metadata['billing_access']['modules'] ?? null)) {
                $metadata['billing_access']['modules']['maintenance'] = true;
                $metadata['billing_access']['modules']['messages'] = $hasChannelManager;
                DB::table('tenants')->where('id', $tenant->id)->update(['metadata' => json_encode($metadata)]);
            }
        }
    }

    public function down(): void
    {
        // Heq VETËM rreshtat e trashëgimit (çmim i ngrirë 0) — një entitlement
        // i blerë më vonë me çmim katalogu nuk preket nga rollback-u.
        DB::table('tenant_module_entitlements')
            ->whereIn('module_code', self::GRANDFATHERED)
            ->where('unit_price_cents', 0)
            ->delete();

        foreach (DB::table('tenants')->get(['id', 'metadata']) as $tenant) {
            $metadata = json_decode($tenant->metadata ?? '[]', true) ?: [];
            if (is_array($metadata['billing_access']['modules'] ?? null)) {
                unset(
                    $metadata['billing_access']['modules']['maintenance'],
                    $metadata['billing_access']['modules']['messages'],
                );
                DB::table('tenants')->where('id', $tenant->id)->update(['metadata' => json_encode($metadata)]);
            }
        }
    }
};
