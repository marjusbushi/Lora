<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $bindings = DB::table('ai_access_tokens')
            ->leftJoin('oauth_access_tokens', 'oauth_access_tokens.id', '=', 'ai_access_tokens.access_token_id')
            ->orderBy('ai_access_tokens.created_at')
            ->orderBy('ai_access_tokens.access_token_id')
            ->get([
                'ai_access_tokens.access_token_id',
                'ai_access_tokens.tenant_id',
                'ai_access_tokens.user_id',
                'ai_access_tokens.client_id as binding_client_id',
                'oauth_access_tokens.id as oauth_token_id',
                'oauth_access_tokens.user_id as oauth_user_id',
                'oauth_access_tokens.client_id as oauth_client_id',
                'ai_access_tokens.created_at',
                'ai_access_tokens.updated_at',
            ]);

        $grants = [];

        foreach ($bindings as $binding) {
            if (! $binding->oauth_token_id || ! $binding->oauth_client_id) {
                throw new RuntimeException(
                    "AI token binding {$binding->access_token_id} has no matching OAuth token/client. Remove the orphan binding before migrating."
                );
            }

            if ($binding->binding_client_id && (string) $binding->binding_client_id !== (string) $binding->oauth_client_id) {
                throw new RuntimeException(
                    "AI token binding {$binding->access_token_id} disagrees with its OAuth client. Repair the binding before migrating."
                );
            }

            if ((string) $binding->user_id !== (string) $binding->oauth_user_id) {
                throw new RuntimeException(
                    "AI token binding {$binding->access_token_id} disagrees with its OAuth user. Repair the binding before migrating."
                );
            }

            if (! DB::table('oauth_clients')->where('id', $binding->oauth_client_id)->exists()) {
                throw new RuntimeException(
                    "OAuth client {$binding->oauth_client_id} no longer exists. Revoke its AI token binding before migrating."
                );
            }

            $key = $binding->user_id.'|'.$binding->oauth_client_id;

            if (isset($grants[$key]) && (int) $grants[$key]['tenant_id'] !== (int) $binding->tenant_id) {
                throw new RuntimeException(
                    "OAuth client {$binding->oauth_client_id} is bound to multiple hotels for user {$binding->user_id}. Revoke the conflicting AI connections before migrating."
                );
            }

            $grants[$key] ??= [
                'tenant_id' => $binding->tenant_id,
                'user_id' => $binding->user_id,
                'client_id' => $binding->oauth_client_id,
                'created_at' => $binding->created_at ?? now(),
                'updated_at' => $binding->updated_at ?? now(),
            ];
        }

        // MySQL DDL auto-commits. These guards make a retry safe if a later
        // data step fails before Laravel records the migration as complete.
        if (! Schema::hasTable('ai_oauth_legacy_revocations')) {
            Schema::create('ai_oauth_legacy_revocations', function (Blueprint $table) {
                $table->id();
                $table->string('token_type', 20);
                $table->string('token_id', 100);
                $table->timestamps();
                $table->unique(['token_type', 'token_id']);
            });
        }

        if (! Schema::hasTable('ai_oauth_grants')) {
            Schema::create('ai_oauth_grants', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignUuid('client_id')->constrained('oauth_clients')->cascadeOnDelete();
                $table->timestamps();

                // One OAuth client may represent exactly one hotel for a user.
                $table->unique(['user_id', 'client_id']);
                $table->index(['tenant_id', 'user_id']);
            });
        }

        if ($grants !== []) {
            DB::table('ai_oauth_grants')->insertOrIgnore(array_values($grants));

            foreach ($grants as $expected) {
                $matches = DB::table('ai_oauth_grants')
                    ->where('user_id', $expected['user_id'])
                    ->where('client_id', $expected['client_id'])
                    ->where('tenant_id', $expected['tenant_id'])
                    ->exists();

                if (! $matches) {
                    throw new RuntimeException(
                        "OAuth grant backfill disagrees for user {$expected['user_id']} and client {$expected['client_id']}."
                    );
                }
            }
        }

        // A legacy unbound MCP token must not become valid after a later grant.
        DB::table('oauth_access_tokens')
            ->where('revoked', false)
            ->orderBy('id')
            ->get(['id', 'scopes'])
            ->each(function (object $token): void {
                $scopes = is_string($token->scopes) ? json_decode($token->scopes, true) : $token->scopes;

                if (! is_array($scopes)
                    || ! in_array('mcp:use', $scopes, true)
                    || DB::table('ai_access_tokens')->where('access_token_id', $token->id)->exists()) {
                    return;
                }

                DB::table('ai_oauth_legacy_revocations')->insertOrIgnore([
                    'token_type' => 'access',
                    'token_id' => $token->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('oauth_refresh_tokens')
                    ->where('access_token_id', $token->id)
                    ->where('revoked', false)
                    ->get(['id'])
                    ->each(fn (object $refresh) => DB::table('ai_oauth_legacy_revocations')->insertOrIgnore([
                        'token_type' => 'refresh',
                        'token_id' => $refresh->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]));

                DB::table('oauth_refresh_tokens')->where('access_token_id', $token->id)->update(['revoked' => true]);
                DB::table('oauth_access_tokens')->where('id', $token->id)->update(['revoked' => true]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_oauth_grants');

        if (Schema::hasTable('ai_oauth_legacy_revocations')) {
            DB::table('ai_oauth_legacy_revocations')->orderBy('id')->get()->each(function (object $revocation): void {
                $table = $revocation->token_type === 'refresh'
                    ? 'oauth_refresh_tokens'
                    : 'oauth_access_tokens';

                DB::table($table)->where('id', $revocation->token_id)->update(['revoked' => false]);
            });

            Schema::drop('ai_oauth_legacy_revocations');
        }
    }
};
