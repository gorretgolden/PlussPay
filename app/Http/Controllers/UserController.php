<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $users = User::with('roles')
            ->filtered($request)
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $roles = Role::pluck('name');

        return view('users.index', compact('users', 'roles'));
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
        $currentUser = auth()->user();
        
        if ($user->hasRole('superadmin') && !$currentUser->hasRole('superadmin')) {
            return back()->withErrors(['error' => 'Only superadmins can modify other superadmin accounts.']);
        }

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

        $oldData = $user->only(['name', 'email', 'phone', 'is_active']);
        $oldRole = $user->getRoleNames()->first();

        $user->update([
            'name' => trim($validated['name']),
            'email' => strtolower(trim($validated['email'])),
            'phone' => isset($validated['phone']) ? trim($validated['phone']) : null,
            'is_active' => $request->boolean('is_active'),
        ]);

        if (!empty($validated['password'])) {
            $user->update(['password' => Hash::make($validated['password'])]);
            
            ActivityLog::create([
                'user_id' => $currentUser->id,
                'action' => 'password_reset',
                'description' => "Password reset for user {$user->name} ({$user->email})",
                'old_value' => null,
                'new_value' => 'password_changed',
            ]);
        }

        $user->syncRoles([$validated['role']]);

        if ($oldRole !== $validated['role']) {
            ActivityLog::create([
                'user_id' => $currentUser->id,
                'action' => 'role_changed',
                'description' => "Role changed from {$oldRole} to {$validated['role']} for user {$user->name}",
                'old_value' => $oldRole,
                'new_value' => $validated['role'],
            ]);
        }

        ActivityLog::create([
            'user_id' => $currentUser->id,
            'action' => 'user_updated',
            'description' => "User {$user->name} updated",
            'old_value' => json_encode($oldData),
            'new_value' => json_encode($user->only(['name', 'email', 'phone', 'is_active'])),
        ]);

        return redirect()->route('users.index')->with('success', 'User updated successfully.');
    }

    public function resetPassword(Request $request, User $user)
    {
        $currentUser = auth()->user();
        
        if ($user->hasRole('superadmin') && !$currentUser->hasRole('superadmin')) {
            return back()->withErrors(['error' => 'Only superadmins can reset superadmin passwords.']);
        }

        $validated = $request->validate([
            'password' => 'required|string|min:8|confirmed',
        ], [
            'password.required' => 'New password is required.',
            'password.min' => 'Password must be at least 8 characters.',
            'password.confirmed' => 'Password confirmation does not match.',
        ]);

        $user->update(['password' => Hash::make($validated['password'])]);

        ActivityLog::create([
            'user_id' => $currentUser->id,
            'action' => 'password_reset',
            'description' => "Password reset for user {$user->name} ({$user->email})",
            'old_value' => null,
            'new_value' => 'password_changed',
        ]);

        return back()->with('success', 'Password reset successfully.');
    }

    public function toggleLock(Request $request, User $user)
    {
        $currentUser = auth()->user();

        if ($user->hasRole('superadmin') && !$currentUser->hasRole('superadmin')) {
            return back()->withErrors(['error' => 'Only superadmins can lock/unlock superadmin accounts.']);
        }

        if ($user->id === $currentUser->id) {
            return back()->withErrors(['error' => 'You cannot lock your own account.']);
        }

        if ($user->isLocked()) {
            $user->unlock();
            $action = 'unlocked';
            $description = "Account unlocked for user {$user->name}";
        } else {
            $user->update(['is_locked' => true]);
            $action = 'locked';
            $description = "Account locked for user {$user->name}";
        }

        ActivityLog::create([
            'user_id' => $currentUser->id,
            'action' => $action,
            'description' => $description,
            'old_value' => $user->is_locked ? 'locked' : 'unlocked',
            'new_value' => $user->is_locked ? 'locked' : 'unlocked',
        ]);

        $message = $user->is_locked ? 'Account locked successfully.' : 'Account unlocked successfully.';
        return back()->with('success', $message);
    }

    public function toggleActive(Request $request, User $user)
    {
        $currentUser = auth()->user();

        if ($user->id === $currentUser->id) {
            return back()->withErrors(['error' => 'You cannot deactivate your own account.']);
        }

        if ($user->hasRole('superadmin') && !$currentUser->hasRole('superadmin')) {
            return back()->withErrors(['error' => 'Only superadmins can modify superadmin accounts.']);
        }

        $user->update(['is_active' => !$user->is_active]);

        $status = $user->is_active ? 'activated' : 'deactivated';
        ActivityLog::create([
            'user_id' => $currentUser->id,
            'action' => "user_{$status}",
            'description' => "User {$user->name} {$status}",
        ]);

        return back()->with('success', "User {$user->name} {$status}.");
    }

    public function destroy(User $user)
    {
        $currentUser = auth()->user();

        if ($user->id === $currentUser->id) {
            return back()->withErrors(['error' => 'You cannot delete your own account.']);
        }

        if ($user->hasRole('superadmin')) {
            return back()->withErrors(['error' => 'Cannot delete a Super Admin account.']);
        }

        $name = $user->name;
        $user->delete();

        ActivityLog::create([
            'user_id' => $currentUser->id,
            'action' => 'user_deleted',
            'description' => "User {$name} deleted",
        ]);

        return redirect()->route('users.index')->with('success', "User {$name} deleted.");
    }
}
