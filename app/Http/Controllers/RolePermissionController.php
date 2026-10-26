<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Support\PermissionCatalog;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionController extends Controller
{
    public function index(Request $request)
    {
        $roles = Role::where('guard_name', 'web')->with('permissions')->withCount('users')->latest('id')->get();
        $permissions = $this->groups();
        $selectedRole = $roles->firstWhere('id', (int) $request->query('role')) ?? $roles->firstWhere('name', 'admin') ?? $roles->first();

        return view('admin.roles', compact('roles', 'permissions', 'selectedRole'));
    }

    private function groups()
    {
        return Permission::where('guard_name', 'web')->orderBy('name')->get()
            ->groupBy(fn ($p) => PermissionCatalog::module($p->name));
    }

    public function permissions(Request $request)
    {
        $permissions = $this->groups();
        return view('admin.permissions', compact('permissions'));
    }

    public function store(Request $request)
    {
        return $this->createRole($request);
    }

    /**
     * Role names are stored lowercase and hyphenated so "Desk Lead" and "desk-lead" are the
     * same role, and so the label can be read back out of the name later.
     */
    public static function slug(string $name): string
    {
        return strtolower(trim(preg_replace('/[\s-]+/', '-', $name), '-'));
    }

    private function createRole(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:50', 'regex:/^[a-zA-Z][a-zA-Z0-9 -]*$/'],
            'description' => ['nullable', 'string', 'max:500'],
            ...$this->permissionRules(),
        ]);
        $name = self::slug($validated['name']);
        abort_if(in_array($name, ['superadmin', 'public', 'customer'], true), 403, 'This role name is protected.');
        if (Role::where('name', $name)->where('guard_name', 'web')->exists()) {
            throw ValidationException::withMessages(['name' => 'A role with this name already exists.']);
        }
        $names = $validated['permissions'] ?? [];
        if ($names) {
            Gate::authorize('assign-permissions');
            $this->delegable($names);
        }
        $role = DB::transaction(function () use ($name, $names, $validated) {
            $role = Role::create(['name' => $name, 'guard_name' => 'web']);
            $role->forceFill(['description' => $validated['description'] ?? null, 'permissions_initialized_at' => now()])->save();
            $role->syncPermissions($names);
            $this->audit('role_created', $role, [], $names, 'Created role');
            return $role;
        });
        return redirect()->route('admin.roles', ['role' => $role->id])->with('success', 'Role created.');
    }

    public function update(Request $request, Role $role)
    {
        $this->editable($role);
        $validated = $request->validate([
            'description' => ['nullable', 'string', 'max:500'],
            ...$this->permissionRules(),
        ]);

        Gate::authorize('assign-permissions');
        $names = $validated['permissions'] ?? [];
        $this->delegable($names);
        DB::transaction(function () use ($role, $names, $validated) {
            $role = Role::whereKey($role->id)->lockForUpdate()->firstOrFail();
            $this->editable($role);
            $this->guardSelfLockout($role, $names);
            $before = $role->permissions->pluck('name')->sort()->values()->all();
            $description = $role->description;
            $role->syncPermissions($names);
            $role->forceFill(['description' => $validated['description'] ?? $description])->save();
            $after = $role->fresh()->permissions->pluck('name')->sort()->values()->all();
            if ($before !== $after || $description !== $role->description) {
                $this->audit('role_permissions_changed', $role, $before, $after,
                    'Updated role; description: '.($description ?? '').' → '.($role->description ?? ''));
            }
        });
        return redirect()->route('admin.roles', ['role' => $role->id])->with('success', 'Role permissions saved.');
    }

    public function rename(Request $request, Role $role)
    {
        $this->editable($role);
        Gate::authorize('edit-role');
        // The four roles the catalog assigns defaults to are referenced by name in the seeder,
        // so only a role created here can be renamed.
        abort_if(array_key_exists($role->name, PermissionCatalog::DESCRIPTIONS), 403, 'Built-in roles cannot be renamed.');
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:50', 'regex:/^[a-zA-Z][a-zA-Z0-9 -]*$/'],
        ]);
        $name = self::slug($validated['name']);
        abort_if(in_array($name, ['superadmin', 'public', 'customer'], true), 403, 'This role name is protected.');
        if (Role::where('name', $name)->where('guard_name', 'web')->exists()) {
            throw ValidationException::withMessages(['name' => 'A role with this name already exists.']);
        }

        DB::transaction(function () use ($role, $name) {
            $role = Role::whereKey($role->id)->lockForUpdate()->firstOrFail();
            $before = $role->name;
            $role->forceFill(['name' => $name])->save();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $this->audit('role_renamed', $role, [$before], [$name], 'Renamed '.$before.' to '.$name);
        });

        return redirect()->route('admin.roles', ['role' => $role->id])->with('success', 'Role renamed.');
    }

    public function destroy(Request $request, Role $role)
    {
        $this->editable($role);
        abort_if(array_key_exists($role->name, PermissionCatalog::DESCRIPTIONS), 403, 'Built-in roles cannot be deleted.');
        $request->validate(['confirm_name' => ['required', 'string', Rule::in([$role->name])]]);
        DB::transaction(function () use ($role) {
            $role = Role::whereKey($role->id)->lockForUpdate()->firstOrFail();
            $this->editable($role);
            if ($role->users()->exists()) {
                throw ValidationException::withMessages(['confirm_name' => 'Reassign every user before deleting this role.']);
            }
            $this->audit('role_deleted', $role, $role->permissions->pluck('name')->all(), [], 'Deleted role');
            $role->delete();
        });
        return redirect()->route('admin.roles')->with('success', 'Role deleted.');
    }

    public function toggleRolePermission(Request $request, Permission $permission, Role $role)
    {
        $this->editable($role);
        abort_unless($permission->guard_name === 'web', 422);
        $granted = DB::transaction(function () use ($permission, $role) {
            $role = Role::whereKey($role->id)->lockForUpdate()->firstOrFail();
            $this->editable($role);
            $before = $role->permissions->pluck('name')->all();
            $has = in_array($permission->name, $before, true);
            if (!$has) {
                $this->delegable([$permission->name]);
                $role->givePermissionTo($permission);
            } else {
                $this->guardSelfLockout($role, array_values(array_diff($before, [$permission->name])));
                $role->revokePermissionTo($permission);
            }
            $this->audit('role_permission_toggled', $role, $before, $role->fresh()->permissions->pluck('name')->all(), 'Toggled '.$permission->name);
            return !$has;
        });
        return response()->json(['granted' => $granted]);
    }

    public function bulk(Request $request)
    {
        $validated = $request->validate([
            'action' => ['required', 'in:assign,remove,delete'],
            'permission_ids' => ['required', 'array', 'min:1'],
            'permission_ids.*' => ['integer', Rule::exists('permissions', 'id')->where('guard_name', 'web')],
            'role_id' => ['required_unless:action,delete', 'integer', Rule::exists('roles', 'id')->where('guard_name', 'web')],
        ]);
        abort_if($validated['action'] === 'delete', 403, 'Permission definitions are maintained in the application catalog.');
        $role = Role::findOrFail($validated['role_id']);
        $this->editable($role);
        $names = Permission::whereIn('id', $validated['permission_ids'])->pluck('name')->all();
        if ($validated['action'] === 'assign') {
            $this->delegable($names);
        }
        DB::transaction(function () use ($role, $names, $validated) {
            $role = Role::whereKey($role->id)->lockForUpdate()->firstOrFail();
            $this->editable($role);
            $before = $role->permissions->pluck('name')->all();
            $after = $validated['action'] === 'assign' ? array_unique(array_merge($before, $names)) : array_diff($before, $names);
            if ($validated['action'] === 'remove') {
                $this->guardSelfLockout($role, array_values($after));
            }
            $role->syncPermissions(array_values($after));
            $this->audit('role_permissions_bulk_changed', $role, $before, array_values($after), 'Bulk '.$validated['action']);
        });
        return back()->with('success', 'Role permissions updated.');
    }

    public function seed()
    {
        Gate::authorize('seed-permissions');
        DB::transaction(function () {
            $before = Permission::where('guard_name', 'web')->count();
            (new PermissionsSeeder)->run();
            ActivityLog::create([
                'user_id' => auth()->id(), 'action' => 'permissions_seeded',
                'description' => 'Incrementally seeded the default permission catalog; preserved existing role customizations.',
                'old_value' => (string) $before, 'new_value' => (string) Permission::where('guard_name', 'web')->count(),
            ]);
        });
        return back()->with('success', 'Default catalog seeded. Existing role customizations were preserved.');
    }

    // These URLs remain stable, but abilities wired into code are not cosmetic CRUD data.
    public function storePermission(Request $request) { abort(403, 'Permission definitions are maintained in the application catalog.'); }
    public function updatePermission(Request $request, Permission $permission) { abort(403, 'Permission slugs cannot be renamed.'); }
    public function destroyPermission(Permission $permission) { abort(403, 'Permission definitions cannot be deleted.'); }

    private function permissionRules(): array
    {
        return ['permissions' => ['sometimes', 'array'], 'permissions.*' => ['string', 'distinct', Rule::exists('permissions', 'name')->where('guard_name', 'web')]];
    }

    private function editable(Role $role): void
    {
        abort_unless($role->guard_name === 'web', 404);
        abort_if(in_array($role->name, ['superadmin', 'public', 'customer'], true), 403, 'This role is protected and cannot be changed.');
        // A permissions manager cannot modify or copy a role more privileged than themselves.
        $this->delegable($role->permissions->pluck('name')->all());
    }

    private function delegable(array $names): void
    {
        foreach ($names as $name) {
            abort_unless(PermissionCatalog::mayDelegate(auth()->user(), $name), 403, 'You cannot grant or manage permissions you do not hold.');
        }
    }

    /**
     * People may edit their own role, but never in a way that shuts them out of this page.
     */
    private function guardSelfLockout(Role $role, array $names): void
    {
        $actor = auth()->user();
        if (!$actor->roles->contains('id', $role->id)) {
            return;
        }
        $lost = array_values(array_filter(
            ['view-roles', 'edit-role', 'assign-permissions'],
            fn ($name) => $actor->can($name) && !in_array($name, $names, true),
        ));
        if ($lost) {
            throw ValidationException::withMessages([
                'permissions' => 'Keep these on your own role so you can still reach this page: '
                    .implode(', ', array_map(fn ($name) => PermissionCatalog::label($name), $lost)).'.',
            ]);
        }
    }

    private function audit(string $action, Role $role, array $before, array $after, string $detail): void
    {
        $affected = $role->users()->count();
        ActivityLog::create([
            'user_id' => auth()->id(), 'action' => $action,
            'description' => $detail.' for '.$role->name.'; '.Str::plural('user', $affected).' affected.',
            'old_value' => json_encode($before), 'new_value' => json_encode($after),
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public static function displayName(string $slug): string
    {
        return $slug === 'superadmin' ? 'Super Admin' : ucwords(str_replace('-', ' ', $slug));
    }
}
