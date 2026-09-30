<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index()
    {
        $users = User::with('roles')->latest()->paginate(20);

        return view('users.index', compact('users'));
    }

    public function create()
    {
        $roles = Role::all();

        return view('users.create', compact('roles'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', 'min:2', 'regex:/^[a-zA-Z\s\-\.]+$/'],
            'email' => 'required|email|max:100|unique:users,email',
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9\s\-]{7,20}$/'],
            'password' => 'required|string|min:8|confirmed',
            'role' => 'required|exists:roles,name',
        ], [
            'name.required' => 'Full name is required.',
            'name.min' => 'Name must be at least 2 characters.',
            'name.regex' => 'Name can only contain letters, spaces, hyphens, and dots.',
            'email.required' => 'Email address is required.',
            'email.email' => 'Enter a valid email address.',
            'email.unique' => 'This email is already registered.',
            'phone.regex' => 'Enter a valid phone number.',
            'password.required' => 'Password is required.',
            'password.min' => 'Password must be at least 8 characters.',
            'password.confirmed' => 'Password confirmation does not match.',
            'role.required' => 'Please select a role.',
            'role.exists' => 'Invalid role selected.',
        ]);

        $user = User::create([
            'name' => trim($validated['name']),
            'email' => strtolower(trim($validated['email'])),
            'phone' => !empty($validated['phone']) ? trim($validated['phone']) : null,
            'password' => Hash::make($validated['password']),
            'is_active' => true,
        ]);

        $user->assignRole($validated['role']);

        return redirect()->route('users.index')->with('success', 'User created successfully.');
    }

    public function edit(User $user)
    {
        $roles = Role::all();

        return view('users.edit', compact('user', 'roles'));
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', 'min:2', 'regex:/^[a-zA-Z\s\-\.]+$/'],
            'email' => 'required|email|max:100|unique:users,email,' . $user->id,
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9\s\-]{7,20}$/'],
            'password' => 'nullable|string|min:8|confirmed',
            'role' => 'required|exists:roles,name',
            'is_active' => 'nullable|boolean',
        ], [
            'name.required' => 'Full name is required.',
            'name.min' => 'Name must be at least 2 characters.',
            'name.regex' => 'Name can only contain letters, spaces, hyphens, and dots.',
            'email.required' => 'Email address is required.',
            'email.email' => 'Enter a valid email address.',
            'email.unique' => 'This email is already in use.',
            'phone.regex' => 'Enter a valid phone number.',
            'password.min' => 'Password must be at least 8 characters.',
            'password.confirmed' => 'Password confirmation does not match.',
            'role.required' => 'Please select a role.',
        ]);

        $user->update([
            'name' => trim($validated['name']),
            'email' => strtolower(trim($validated['email'])),
            'phone' => isset($validated['phone']) ? trim($validated['phone']) : null,
            'is_active' => $request->boolean('is_active'),
        ]);

        if (!empty($validated['password'])) {
            $user->update(['password' => Hash::make($validated['password'])]);
        }

        $user->syncRoles([$validated['role']]);

        return redirect()->route('users.index')->with('success', 'User updated successfully.');
    }
}
