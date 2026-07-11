<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 24)->default('active')->index();
            $table->string('billing_cycle', 16)->default('monthly');
            $table->string('currency', 3)->default('EUR');
            $table->unsignedTinyInteger('annual_discount_percent')->default(20);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_ends_at')->nullable()->index();
            $table->timestamp('cancels_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('tenant_module_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('module_code', 40);
            $table->boolean('enabled')->default(false)->index();
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedInteger('unit_price_cents')->nullable();
            $table->unsignedInteger('percentage_bps')->nullable();
            $table->json('pricing_snapshot')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'module_code'], 'tenant_module_unique');
        });

        $now = now();
        $modules = config('lora_modules.modules', []);

        DB::table('tenants')->orderBy('id')->each(function (object $tenant) use ($modules, $now) {
            DB::table('tenant_subscriptions')->insert([
                'tenant_id' => $tenant->id,
                'status' => 'active',
                'billing_cycle' => 'monthly',
                'currency' => $tenant->currency ?: 'EUR',
                'annual_discount_percent' => (int) config('lora_modules.annual_discount_percent', 20),
                'starts_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $roomCount = max(1, (int) DB::table('rooms')->where('tenant_id', $tenant->id)->count());

            foreach ($modules as $code => $module) {
                DB::table('tenant_module_entitlements')->insert([
                    'tenant_id' => $tenant->id,
                    'module_code' => $code,
                    'enabled' => true,
                    'quantity' => $code === 'channel_manager' ? $roomCount : 1,
                    'unit_price_cents' => $module['unit_price_cents'] ?? null,
                    'percentage_bps' => $module['percentage_bps'] ?? null,
                    'pricing_snapshot' => json_encode($module, JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $metadata = json_decode((string) ($tenant->metadata ?? '{}'), true);
            $metadata = is_array($metadata) ? $metadata : [];
            $metadata['billing_access'] = [
                'status' => 'active',
                'billing_cycle' => 'monthly',
                'current_period_ends_at' => null,
                'modules' => array_fill_keys(array_keys($modules), true),
            ];

            DB::table('tenants')->where('id', $tenant->id)->update([
                'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                'updated_at' => $now,
            ]);
        });
    }

    public function down(): void
    {
        DB::table('tenants')->orderBy('id')->each(function (object $tenant) {
            $metadata = json_decode((string) ($tenant->metadata ?? '{}'), true);
            $metadata = is_array($metadata) ? $metadata : [];
            unset($metadata['billing_access']);

            DB::table('tenants')->where('id', $tenant->id)->update([
                'metadata' => $metadata ? json_encode($metadata, JSON_THROW_ON_ERROR) : null,
            ]);
        });

        Schema::dropIfExists('tenant_module_entitlements');
        Schema::dropIfExists('tenant_subscriptions');
    }
};
