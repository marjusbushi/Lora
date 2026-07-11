<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status', 20)->default('active');
            $table->string('timezone', 50)->default('Europe/Tirane');
            $table->string('currency', 3)->default('EUR');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });

        Schema::create('tenant_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('domain')->unique();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });

        Schema::create('tenant_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 50);
            $table->boolean('enabled')->default(false);
            $table->text('credentials')->nullable();
            $table->json('configuration')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'provider']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('current_tenant_id')->nullable()->after('id')->constrained('tenants')->nullOnDelete();
            $table->boolean('is_super_admin')->default(false)->after('password')->index();
        });

        Schema::create('tenant_user', function (Blueprint $table) {
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_owner')->default(false);
            $table->timestamps();

            $table->primary(['tenant_id', 'user_id']);
        });

        $now = now();
        $name = DB::table('settings')
            ->where('group', 'hotel')
            ->where('key', 'name')
            ->value('value') ?: config('app.name', 'Default Hotel');

        $tenantId = DB::table('tenants')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'slug' => $this->uniqueSlug($name),
            'status' => 'active',
            'timezone' => 'Europe/Tirane',
            'currency' => 'EUR',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $host = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            DB::table('tenant_domains')->insert([
                'tenant_id' => $tenantId,
                'domain' => Str::lower($host),
                'is_primary' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $channexApiKey = (string) config('services.channex.api_key');
        $channexPropertyId = (string) config('services.channex.property_id');
        if ($channexApiKey !== '' && $channexPropertyId !== '') {
            DB::table('tenant_integrations')->insert([
                'tenant_id' => $tenantId,
                'provider' => 'channex',
                'enabled' => true,
                'credentials' => Crypt::encryptString(json_encode([
                    'api_key' => $channexApiKey,
                    'webhook_secret' => (string) config('services.channex.webhook_secret'),
                ], JSON_THROW_ON_ERROR)),
                'configuration' => json_encode([
                    'base_url' => (string) config('services.channex.base_url'),
                    'property_id' => $channexPropertyId,
                    'state_length_days' => (int) config('services.channex.state_length_days', 500),
                ], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $pokKeyId = (string) config('services.pok.key_id');
        $pokKeySecret = (string) config('services.pok.key_secret');
        $pokMerchantId = (string) config('services.pok.merchant_id');
        if ($pokKeyId !== '' && $pokKeySecret !== '' && $pokMerchantId !== '') {
            DB::table('tenant_integrations')->insert([
                'tenant_id' => $tenantId,
                'provider' => 'pok',
                'enabled' => true,
                'credentials' => Crypt::encryptString(json_encode([
                    'key_id' => $pokKeyId,
                    'key_secret' => $pokKeySecret,
                ], JSON_THROW_ON_ERROR)),
                'configuration' => json_encode([
                    'merchant_id' => $pokMerchantId,
                    'production' => (bool) config('services.pok.production'),
                    'base_url' => (string) config('services.pok.base_url'),
                ], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('users')->orderBy('id')->each(function (object $user) use ($tenantId, $now) {
            DB::table('users')->where('id', $user->id)->update([
                'current_tenant_id' => $tenantId,
                'is_super_admin' => in_array(Str::lower($user->email), [
                    'marjusbushi.mb@gmail.com',
                    'marjusbushi@zeroabsolute.com',
                ], true),
            ]);

            DB::table('tenant_user')->insertOrIgnore([
                'tenant_id' => $tenantId,
                'user_id' => $user->id,
                'is_owner' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_user');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_tenant_id');
            $table->dropColumn('is_super_admin');
        });

        Schema::dropIfExists('tenant_integrations');
        Schema::dropIfExists('tenant_domains');
        Schema::dropIfExists('tenants');
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'default-hotel';
        $slug = $base;
        $suffix = 2;

        while (DB::table('tenants')->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
};
