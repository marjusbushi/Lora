<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\TenantRoleService;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reconcile every tenant's STANDARD roles (the names in
 * TenantRoleService::definitions()) with the current definitions — the tool
 * behind plan #724's rollout. Custom roles are never touched. Dry-run prints
 * the exact ADD/REMOVE per role so the diff can be approved before applying.
 *
 * Roles are matched by the (name, team_id) PAIR — Spatie's name-only lookups
 * are NOT team-scoped and silently hit another tenant's role (proven on task
 * #7722).
 */
class SyncRoleDefinitionsCommand extends Command
{
    protected $signature = 'roles:sync-definitions {--dry-run : Print the per-role diff without changing anything} {--tenant= : Only this tenant id}';

    protected $description = 'Sync the standard roles of every tenant to the current TenantRoleService definitions';

    public function handle(TenantContext $context, TenantRoleService $roles): int
    {
        $tenants = $this->option('tenant')
            ? Tenant::query()->whereKey((int) $this->option('tenant'))->get()
            : Tenant::query()->orderBy('id')->get();
        if ($tenants->isEmpty()) {
            $this->error('No tenants matched.');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $allPermissionNames = collect(TenantRoleService::permissionNames());

        foreach ($tenants as $tenant) {
            $this->info(($dry ? '[DRY-RUN] ' : '')."Tenant {$tenant->id} — {$tenant->name}");

            foreach (TenantRoleService::definitions() as $roleName => $target) {
                $targetNames = $target === '*' ? $allPermissionNames : collect($target);

                // (name, team_id) pair — never name alone (task #7722).
                $role = DB::table('roles')
                    ->where('name', $roleName)
                    ->where(config('permission.column_names.team_foreign_key', 'team_id'), $tenant->id)
                    ->first();
                $current = $role
                    ? DB::table('role_has_permissions')
                        ->join('permissions', 'permissions.id', 'role_has_permissions.permission_id')
                        ->where('role_id', $role->id)
                        ->pluck('permissions.name')
                    : collect();

                $add = $targetNames->diff($current)->sort()->values();
                $remove = $current->diff($targetNames)->sort()->values();

                if ($add->isEmpty() && $remove->isEmpty()) {
                    $this->line("  {$roleName}: in sync");

                    continue;
                }
                $this->line("  {$roleName}:".($role ? '' : ' (will be created)'));
                foreach ($add as $name) {
                    $this->line("    + {$name}");
                }
                foreach ($remove as $name) {
                    $this->line("    - {$name}");
                }
            }

            if (! $dry) {
                // provision() is idempotent, team-safe (runs inside the tenant
                // context) and only touches the standard role names.
                $roles->provision($tenant);
                $this->info("  applied.");
            }
        }

        return self::SUCCESS;
    }
}
