<?php

namespace App\Http\Controllers;

use App\Http\Requests\UserStoreRequest;
use App\Http\Requests\UserUpdateRequest;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(): Response
    {
        $users = User::select('id', 'name', 'email', 'created_at', 'deleted_at')
            ->withTrashed()
            ->with('roles:id,name')
            ->orderBy('name')
            ->paginate(15);

        return Inertia::render('Users/Index', [
            'users' => $users,
            'roles' => Role::pluck('name'),
            'permissionModules' => $this->permissionModules(),
            'rolesDetailed' => Role::with('permissions:id,name')->orderBy('name')->get()->map(fn ($r) => [
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
        if ($role->name === 'admin') {
            return back()->with('error', 'Roli admin ka gjithmone akses te plote dhe nuk kufizohet.');
        }

        $data = $request->validate([
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::in(Permission::pluck('name'))],
        ]);

        $role->syncPermissions($data['permissions'] ?? []);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        AuditLog::record('role.permissions.update', null, ['role' => $role->name, 'count' => count($data['permissions'] ?? [])]);

        return back()->with('success', "Lejet e rolit '{$role->name}' u ruajten.");
    }

    public function storeRole(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/', 'unique:roles,name'],
        ]);

        Role::create(['name' => $data['name'], 'guard_name' => 'web']);
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        AuditLog::record('role.create', null, ['role' => $data['name']]);

        return back()->with('success', "Roli '{$data['name']}' u krijua.");
    }

    public function store(UserStoreRequest $request): RedirectResponse
    {
        $user = User::create([
            'name' => $request->validated('name'),
            'email' => $request->validated('email'),
            'password' => bcrypt($request->validated('password')),
        ]);

        $user->assignRole($request->validated('role'));

        AuditLog::record('user.create', $user, ['role' => $request->validated('role')]);

        return back()->with('success', 'Perdoruesi u krijua me sukses.');
    }

    public function update(UserUpdateRequest $request, User $user): RedirectResponse
    {
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

        $user->delete();

        AuditLog::record('user.delete', $user);

        return back()->with('success', 'Perdoruesi u deaktivizua.');
    }

    public function restore(int $id): RedirectResponse
    {
        $user = User::withTrashed()->findOrFail($id);
        $user->restore();

        return back()->with('success', 'Perdoruesi u riaktivizua.');
    }
}
