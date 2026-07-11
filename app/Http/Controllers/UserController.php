<?php

namespace App\Http\Controllers;

use App\Http\Requests\UserStoreRequest;
use App\Http\Requests\UserUpdateRequest;
use App\Models\AuditLog;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $tenantId = app(TenantContext::class)->id();
        $roles = Role::query()
            ->where('team_id', $tenantId)
            ->orderBy('name');
        $roleNames = (clone $roles)->pluck('name');

        $searchInput = $request->input('search', '');
        $roleInput = $request->input('role', '');
        $statusInput = $request->input('status', '');
        $filters = [
            'search' => is_string($searchInput) ? mb_substr(trim($searchInput), 0, 100) : '',
            'role' => is_string($roleInput) && $roleNames->contains($roleInput) ? $roleInput : '',
            'status' => is_string($statusInput) && in_array($statusInput, ['active', 'inactive'], true)
                ? $statusInput
                : '',
        ];

        $userQuery = User::withoutGlobalScope('tenant_membership')
            ->withTrashed()
            ->join('tenant_user', function ($join) use ($tenantId) {
                $join->on('tenant_user.user_id', '=', 'users.id')
                    ->where('tenant_user.tenant_id', $tenantId);
            })
            ->select(
                'users.id', 'users.name', 'users.email', 'users.created_at', 'users.deleted_at',
                'tenant_user.is_active as membership_active',
            )
            ->with('roles:id,name');

        if ($filters['search'] !== '') {
            $userQuery->where(function ($query) use ($filters) {
                $needle = '%'.$filters['search'].'%';
                $query->where('users.name', 'like', $needle)
                    ->orWhere('users.email', 'like', $needle);
            });
        }

        if ($filters['role'] !== '') {
            $userQuery->whereHas('roles', fn ($query) => $query
                ->where('roles.name', $filters['role'])
                ->where('roles.team_id', $tenantId));
        }

        if ($filters['status'] !== '') {
            $userQuery->where('tenant_user.is_active', $filters['status'] === 'active');
        }

        $users = $userQuery
            ->orderByDesc('tenant_user.is_active')
            ->orderBy('users.name')
            ->paginate(15)
            ->withQueryString();

        $membershipStats = DB::table('tenant_user')
            ->where('tenant_id', $tenantId)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active')
            ->first();
        $total = (int) ($membershipStats->total ?? 0);
        $active = (int) ($membershipStats->active ?? 0);

        return Inertia::render('Users/Index', [
            'users' => $users,
            'roles' => $roleNames,
            'filters' => $filters,
            'stats' => [
                'total' => $total,
                'active' => $active,
                'inactive' => max(0, $total - $active),
                'roles' => $roleNames->count(),
            ],
            'permissionModules' => $this->permissionModules(),
            'rolesDetailed' => (clone $roles)->with('permissions:id,name')->get()->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'permissions' => $r->permissions->pluck('name'),
                // The admin role keeps full access and is not editable, so an admin can never lock themselves out.
                'editable' => $r->name !== 'admin',
            ]),
        ]);
    }

    /** Modules + their CRUD-style actions; permission name = "{action}_{key}". */
    private function permissionModules(): array
    {
        return [
            ['key' => 'rooms', 'label' => 'Dhomat', 'actions' => ['view', 'create', 'update', 'delete']],
            ['key' => 'reservations', 'label' => 'Rezervimet', 'actions' => ['view', 'create', 'update', 'delete']],
            ['key' => 'guests', 'label' => 'Mysafiret', 'actions' => ['view', 'create', 'update', 'delete']],
            ['key' => 'housekeeping', 'label' => 'Housekeeping', 'actions' => ['view', 'create', 'update', 'delete']],
            ['key' => 'pos_orders', 'label' => 'POS', 'actions' => ['view', 'create', 'update', 'delete']],
            ['key' => 'pos_shift', 'label' => 'Turnet POS', 'actions' => ['open', 'close', 'close_any']],
            ['key' => 'reports', 'label' => 'Raporte', 'actions' => ['view']],
            ['key' => 'settings', 'label' => 'Settings', 'actions' => ['view', 'update']],
            ['key' => 'users', 'label' => 'Perdoruesit', 'actions' => ['view', 'create', 'update', 'delete']],
        ];
    }

    public function updateRolePermissions(Request $request, Role $role): RedirectResponse
    {
        $this->ensureCurrentTenantRole($role);

        if ($role->name === 'admin') {
            return back()->with('error', 'Roli admin ka gjithmone akses te plote dhe nuk kufizohet.');
        }

        $data = $request->validate([
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::in(Permission::pluck('name'))],
        ]);

        $role->syncPermissions($data['permissions'] ?? []);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        AuditLog::record('role.permissions.update', null, ['role' => $role->name, 'count' => count($data['permissions'] ?? [])]);

        return back()->with('success', "Lejet e rolit '{$role->name}' u ruajten.");
    }

    public function storeRole(Request $request): RedirectResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('roles', 'name')->where('team_id', $tenantId)->where('guard_name', 'web'),
            ],
        ]);

        Role::create(['team_id' => $tenantId, 'name' => $data['name'], 'guard_name' => 'web']);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        AuditLog::record('role.create', null, ['role' => $data['name']]);

        return back()->with('success', "Roli '{$data['name']}' u krijua.");
    }

    public function store(UserStoreRequest $request): RedirectResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $existing = User::withoutGlobalScopes()
            ->withTrashed()
            ->where('email', $request->validated('email'))
            ->first();

        if ($existing) {
            $user = $existing;
            if ($user->trashed()) {
                $user->restore();
            }
            $user->tenants()->syncWithoutDetaching([
                $tenantId => ['is_owner' => false, 'is_active' => true],
            ]);
        } else {
            $user = User::create([
                'name' => $request->validated('name'),
                'email' => $request->validated('email'),
                'password' => bcrypt($request->validated('password')),
            ]);
        }

        $user->unsetRelation('roles');
        $user->assignRole($request->validated('role'));

        AuditLog::record('user.create', $user, ['role' => $request->validated('role')]);

        return back()->with('success', $existing
            ? 'Llogaria ekzistuese u lidh me kete hotel.'
            : 'Perdoruesi u krijua me sukses.');
    }

    public function update(UserUpdateRequest $request, User $user): RedirectResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $isOwner = DB::table('tenant_user')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->where('is_owner', true)
            ->exists();

        if (($isOwner || $user->id === auth()->id()) && $request->validated('role') !== 'admin') {
            return back()->withErrors([
                'role' => $isOwner
                    ? 'Pronari i hotelit duhet te mbetet admin.'
                    : 'Nuk mund t\'i heqesh vetes rolin admin.',
            ]);
        }

        $sharedAccount = DB::table('tenant_user')
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->count() > 1;

        if ($sharedAccount && (
            $request->validated('name') !== $user->name
            || $request->validated('email') !== $user->email
            || filled($request->validated('password'))
        )) {
            throw ValidationException::withMessages([
                'email' => 'Kjo llogari perdoret ne disa hotele. Ketu mund te ndryshosh vetem rolin.',
            ]);
        }

        $data = [
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
        ];

        if ($request->validated('password')) {
            $data['password'] = bcrypt($request->validated('password'));
        }

        $user->update($data);
        $user->syncRoles([$request->validated('role')]);

        AuditLog::record('user.update', $user, ['role' => $request->validated('role')]);

        return back()->with('success', 'Perdoruesi u perditesua me sukses.');
    }

    public function destroy(User $user): RedirectResponse
    {
        if ($user->id === auth()->id()) {
            return back()->with('error', 'Nuk mund te fshish veten.');
        }

        // The self-seeded system user that every public/website booking is attributed to.
        // Deleting it (it was soft-deleted once, causing an 11-day booking outage) must be
        // impossible from the UI — the booking funnel depends on it existing.
        if ($user->email === 'system@villamucho.local') {
            return back()->with('error', 'Ky eshte perdoruesi i sistemit per rezervimet online — nuk mund te fshihet.');
        }

        $tenantId = app(TenantContext::class)->id();
        $isOwner = DB::table('tenant_user')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->id)
            ->where('is_owner', true)
            ->exists();
        if ($isOwner) {
            return back()->with('error', 'Pronari i hotelit nuk mund te çaktivizohet.');
        }

        DB::transaction(function () use ($tenantId, $user) {
            DB::table('tenant_user')
                ->where('tenant_id', $tenantId)
                ->where('user_id', $user->id)
                ->update(['is_active' => false, 'updated_at' => now()]);

            $stillActive = DB::table('tenant_user')
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->exists();

            if (! $stillActive) {
                $user->delete();
            }
        });

        AuditLog::record('user.deactivate', $user, ['tenant_id' => $tenantId]);

        return back()->with('success', 'Perdoruesi u deaktivizua.');
    }

    public function restore(int $id): RedirectResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $user = User::withoutGlobalScopes()
            ->withTrashed()
            ->whereKey($id)
            ->whereHas('tenants', fn ($query) => $query->whereKey($tenantId))
            ->firstOrFail();

        DB::transaction(function () use ($tenantId, $user) {
            $user->restore();
            $user->forceFill(['current_tenant_id' => $tenantId])->save();
            DB::table('tenant_user')
                ->where('tenant_id', $tenantId)
                ->where('user_id', $user->id)
                ->update(['is_active' => true, 'updated_at' => now()]);
        });

        AuditLog::record('user.reactivate', $user, ['tenant_id' => $tenantId]);

        return back()->with('success', 'Perdoruesi u riaktivizua.');
    }

    private function ensureCurrentTenantRole(Role $role): void
    {
        abort_unless(
            (int) $role->team_id === app(TenantContext::class)->id(),
            404,
        );
    }
}
