<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolePermissionController extends Controller
{
    public function roles()
    {
        $roles = Role::with('permissions')->get();
        $permissions = Permission::all();
        return view('admin.roles', compact('roles', 'permissions'));
    }

    public function updateRole(Request $request, Role $role)
    {
        $request->validate([
            'permissions' => 'array',
            'permissions.*' => 'exists:permissions,name',
        ]);

        $role->syncPermissions($request->permissions ?? []);

        return redirect()->route('admin.roles')->with('success', 'Role permissions updated.');
    }

    public function permissions()
    {
        $permissions = Permission::all();
        $roles = Role::all();
        return view('admin.permissions', compact('permissions', 'roles'));
    }
}
