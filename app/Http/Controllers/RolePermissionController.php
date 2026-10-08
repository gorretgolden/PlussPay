<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolePermissionController extends Controller
{
    public function index()
    {
        $roles = Role::with('permissions')->withCount('users')->latest()->get();
        $permissions = Permission::latest()->get()->groupBy(function ($p) {
            return Str::before($p->name, '-');
        });

        return view('admin.roles', compact('roles', 'permissions'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:50',
            'permissions' => 'array',
            'permissions.*' => 'exists:permissions,name',
        ]);

        $name = strtolower(str_replace(' ', '', $validated['name']));

        if (Role::where('name', $name)->exists()) {
            return back()->withInput()->withErrors(['name' => 'A role with this name already exists.']);
        }

        $role = Role::create(['name' => $name, 'guard_name' => 'web']);
        $role->syncPermissions($validated['permissions'] ?? []);

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'role_created',
            'description' => "Role {$role->name} created with " . count($validated['permissions'] ?? []) . ' permission(s)',
            'new_value' => json_encode($role->fresh()->permissions->pluck('name')->all()),
        ]);

        return redirect()->route('admin.roles')->with('success', "Role \"{$validated['name']}\" created.");
    }

    public function update(Request $request, Role $role)
    {
        if ($role->name === 'superadmin' && !auth()->user()->hasRole('superadmin')) {
            abort(403, 'Only superadmins can change the Super Admin role permissions.');
        }

        $request->validate([
            'permissions' => 'array',
            'permissions.*' => 'exists:permissions,name',
        ]);

        $before = $role->permissions->pluck('name')->sort()->values()->all();
        $role->syncPermissions($request->permissions ?? []);
        $after = $role->refresh()->permissions->pluck('name')->sort()->values()->all();

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'role_permissions_changed',
            'description' => 'Permissions updated for role ' . $role->name,
            'old_value' => json_encode($before),
            'new_value' => json_encode($after),
        ]);

        return redirect()->route('admin.roles')->with('success', "Permissions updated for {$this->displayName($role->name)}.");
    }

    public function destroy(Request $request, Role $role)
    {
        if (in_array($role->name, ['superadmin', 'admin', 'agent', 'staff'], true)) {
            return back()->withErrors(['error' => 'Built-in roles cannot be deleted.']);
        }

        if ($role->users()->count() > 0) {
            return back()->withErrors(['error' => "Cannot delete {$this->displayName($role->name)}: {$role->users()->count()} user(s) still assigned to this role."]);
        }

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'role_deleted',
            'description' => "Role {$role->name} deleted",
            'old_value' => $role->name,
        ]);

        $role->delete();

        return redirect()->route('admin.roles')->with('success', 'Role deleted successfully.');
    }

    public function permissions(Request $request)
    {
        $query = Permission::latest();

        if ($search = $request->input('search')) {
            $query->where('name', 'like', "%{$search}%");
        }

        $permissions = $query->paginate(25)->withQueryString();
        $roles = Role::with('permissions')->latest()->get();
        $totalPermissions = Permission::count();

        return view('admin.permissions', compact('permissions', 'roles', 'totalPermissions'));
    }

    public function toggleRolePermission(Request $request, Permission $permission, Role $role)
    {
        if ($role->name === 'superadmin' && !auth()->user()->hasRole('superadmin')) {
            return response()->json(['error' => 'Only superadmins can change Super Admin permissions.'], 403);
        }

        $before = $role->hasPermissionTo($permission);

        if ($before) {
            $role->revokePermissionTo($permission);
        } else {
            $role->givePermissionTo($permission);
        }

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'role_permission_toggled',
            'description' => ($before ? 'Revoked' : 'Granted') . " {$permission->name} " . ($before ? 'from' : 'to') . " role {$role->name}",
            'old_value' => $before ? 'granted' : 'revoked',
            'new_value' => $before ? 'revoked' : 'granted',
        ]);

        return response()->json(['granted' => !$before]);
    }

    public function storePermission(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
        ]);

        $name = strtolower(str_replace(' ', '-', $validated['name']));

        if (Permission::where('name', $name)->exists()) {
            return back()->withInput()->withErrors(['name' => 'A permission with this name already exists.']);
        }

        $permission = Permission::create(['name' => $name, 'guard_name' => 'web']);

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'permission_created',
            'description' => "Permission {$permission->name} created",
            'new_value' => $permission->name,
        ]);

        return redirect()->route('admin.permissions')->with('success', "Permission \"{$permission->name}\" created.");
    }

    public function updatePermission(Request $request, Permission $permission)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
        ]);

        $oldName = $permission->name;
        $newName = strtolower(str_replace(' ', '-', $validated['name']));

        if ($newName !== $oldName && Permission::where('name', $newName)->exists()) {
            return back()->withErrors(['error' => "A permission named \"{$newName}\" already exists."]);
        }

        $permission->update(['name' => $newName]);

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'permission_updated',
            'description' => "Permission renamed from {$oldName} to {$newName}",
            'old_value' => $oldName,
            'new_value' => $newName,
        ]);

        return redirect()->route('admin.permissions')->with('success', "Permission renamed to \"{$newName}\".");
    }

    public function destroyPermission(Permission $permission)
    {
        $roleCount = $permission->roles()->count();
        $userCount = \DB::table('model_has_permissions')
            ->where('permission_id', $permission->id)
            ->count();

        if ($roleCount > 0 || $userCount > 0) {
            return back()->withErrors(['error' => "Cannot delete \"{$permission->name}\": assigned to {$roleCount} role(s) and {$userCount} user(s). Remove it from all roles and users first."]);
        }

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'permission_deleted',
            'description' => "Permission {$permission->name} deleted",
            'old_value' => $permission->name,
        ]);

        $permission->delete();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return redirect()->route('admin.permissions')->with('success', 'Permission deleted successfully.');
    }

    public function bulk(Request $request)
    {
        $validated = $request->validate([
            'action' => 'required|in:assign,remove,delete',
            'permission_ids' => 'required|array|min:1',
            'permission_ids.*' => 'integer|exists:permissions,id',
            'role_id' => 'required_if:action,assign,remove|integer|exists:roles,id',
        ]);

        $ids = collect($validated['permission_ids'])->unique()->values();
        $permissions = Permission::whereIn('id', $ids)->get();
        $action = $validated['action'];

        if ($action === 'delete') {
            $deletable = $permissions->filter(function ($permission) {
                return $permission->roles()->count() === 0
                    && \DB::table('model_has_permissions')->where('permission_id', $permission->id)->count() === 0;
            });

            $skipped = $permissions->count() - $deletable->count();

            foreach ($deletable as $permission) {
                ActivityLog::create([
                    'user_id' => auth()->id(),
                    'action' => 'permission_deleted',
                    'description' => "Permission {$permission->name} deleted (bulk)",
                    'old_value' => $permission->name,
                ]);
                $permission->delete();
            }

            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

            $message = $deletable->count() . ' permission(s) deleted.';
            if ($skipped > 0) {
                return back()->with('success', $message)->withErrors(['error' => "{$skipped} permission(s) were skipped because they are still assigned to a role or user."]);
            }

            return back()->with('success', $message);
        }

        $role = Role::findOrFail($validated['role_id']);

        if ($role->name === 'superadmin' && !auth()->user()->hasRole('superadmin')) {
            return back()->withErrors(['error' => 'Only superadmins can change Super Admin permissions.']);
        }

        $changed = 0;

        foreach ($permissions as $permission) {
            $hasIt = $role->hasPermissionTo($permission);

            if ($action === 'assign' && !$hasIt) {
                $role->givePermissionTo($permission);
                $changed++;
            } elseif ($action === 'remove' && $hasIt) {
                $role->revokePermissionTo($permission);
                $changed++;
            }
        }

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'role_permissions_bulk_changed',
            'description' => ucfirst($action) . " {$changed} permission(s) " . ($action === 'assign' ? 'to' : 'from') . " role {$role->name}",
            'new_value' => json_encode($permissions->pluck('name')->all()),
        ]);

        return back()->with('success', "{$changed} permission(s) " . ($action === 'assign' ? "added to" : "removed from") . " {$this->displayName($role->name)}.");
    }

    public static function displayName(string $slug): string
    {
        $map = [
            'superadmin' => 'Super Admin',
        ];

        return $map[$slug] ?? ucwords(str_replace('-', ' ', $slug));
    }
}
