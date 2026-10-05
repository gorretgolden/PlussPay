<?php

namespace App\Http\Controllers;

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

        return redirect()->route('admin.roles')->with('success', "Role \"{$validated['name']}\" created.");
    }

    public function update(Request $request, Role $role)
    {
        $request->validate([
            'permissions' => 'array',
            'permissions.*' => 'exists:permissions,name',
        ]);

        $role->syncPermissions($request->permissions ?? []);

        return redirect()->route('admin.roles')->with('success', "Permissions updated for {$this->displayName($role->name)}.");
    }

    public function destroy(Role $role)
    {
        if ($role->users()->count() > 0) {
            return back()->withErrors(['error' => "Cannot delete {$this->displayName($role->name)}: {$role->users()->count()} user(s) still assigned to this role."]);
        }

        if (in_array($role->name, ['superadmin'])) {
            return back()->withErrors(['error' => 'Cannot delete the Super Admin role.']);
        }

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
