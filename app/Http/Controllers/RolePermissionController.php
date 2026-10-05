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
        $roles = Role::with('permissions')->withCount('users')->get();
        $permissions = Permission::orderBy('name')->get()->groupBy(function ($p) {
            return Str::before($p->name, '-');
        });

        return view('admin.roles', compact('roles', 'permissions'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:50|unique:roles,name',
        ], [
            'name.unique' => 'A role with this name already exists.',
        ]);

        $role = Role::create(['name' => strtolower(str_replace(' ', '', $validated['name'])), 'guard_name' => 'web']);

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'role_created',
            'description' => "Role {$role->name} created",
            'new_value' => $role->name,
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

    public function permissions()
    {
        $permissions = Permission::orderBy('name')->get();
        $roles = Role::all();

        return view('admin.permissions', compact('permissions', 'roles'));
    }

    public static function displayName(string $slug): string
    {
        $map = [
            'superadmin' => 'Super Admin',
        ];

        return $map[$slug] ?? ucwords(str_replace('-', ' ', $slug));
    }
}
