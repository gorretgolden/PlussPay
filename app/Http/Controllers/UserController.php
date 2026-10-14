<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Setting;
use App\Models\User;
use App\Services\TotpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    private const PASSWORD_RULES = 'string|min:8|max:72|regex:/[a-zA-Z]/|regex:/[0-9]/';

    private const PASSWORD_MESSAGES = [
        'password.min' => 'Password must be at least 8 characters.',
        'password.max' => 'Password cannot be longer than 72 characters.',
        'password.regex' => 'Password must contain both letters and at least one number.',
        'password.confirmed' => 'Password confirmation does not match.',
    ];

    /**
     * First and last name, each starting with a letter. Casing is corrected by
     * the model, so only the shape is checked here.
     */
    private const NAME_PATTERN = '/^[a-zA-Z][a-zA-Z.\-]*(\s+[a-zA-Z][a-zA-Z.\-]*)+$/u';

    private const NAME_MESSAGE = 'Enter the full name with a first and last name, like Gorret Golden.';

    private const BULK_ACTIONS = [
        'activate' => 'Activated',
        'deactivate' => 'Deactivated',
        'lock' => 'Locked',
        'unlock' => 'Unlocked',
        'window_weekdays' => 'Applied weekday login hours to',
        'window_clear' => 'Removed login hour limits from',
        'two_factor_off' => 'Turned off two-factor sign-in for',
    ];

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
        $roles = $this->assignableRoles();

        return view('users.create', compact('roles'));
    }

    public function store(Request $request)
    {
        $validated = $this->validateUser($request);

        if (!$this->canAssignRole($validated['role'])) {
            return back()->withErrors(['role' => 'Only a superadmin can create a superadmin account.']);
        }

        $email = strtolower(trim($validated['email']));
        $phone = User::normalizePhone($validated['phone'] ?? null);

        if ($clash = $this->emailHolder($email)) {
            return $this->duplicateBack($request, 'email', $clash);
        }

        if ($phone && $clash = $this->phoneHolder($phone)) {
            return $this->duplicateBack($request, 'phone', $clash);
        }

        $user = new User([
            'name' => $validated['name'],
            'email' => $email,
            'phone' => $phone,
            'password' => Hash::make($validated['password']),
            'is_active' => true,
        ] + $this->countryAttributes($validated));

        $this->applyLoginWindow($user, $request);
        $user->save();

        $user->assignRole($validated['role']);

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'user_created',
            'description' => "User {$user->name} ({$user->email}) created with role {$validated['role']}",
            'new_value' => $validated['role'],
        ]);

        return redirect()->route('users.index')->with('success', 'User created successfully.');
    }

    public function edit(User $user)
    {
        if ($user->hasRole('superadmin') && !auth()->user()->hasRole('superadmin')) {
            abort(403, 'Only superadmins can open a superadmin account.');
        }

        $roles = $this->assignableRoles();
        $current = $user->getRoleNames()->first();

        if ($current && !$roles->contains('name', $current)) {
            $roles = $roles->concat(Role::where('name', $current)->get());
        }

        return view('users.edit', compact('user', 'roles'));
    }

    public function update(Request $request, User $user)
    {
        $currentUser = auth()->user();

        if ($user->hasRole('superadmin') && !$currentUser->hasRole('superadmin')) {
            return back()->withErrors(['error' => 'Only superadmins can modify other superadmin accounts.']);
        }

        $validated = $this->validateUser($request, $user);

        $oldRole = $user->getRoleNames()->first();
        $roleChanging = $oldRole !== $validated['role'];

        if ($roleChanging) {
            if ($user->id === $currentUser->id) {
                return back()->withErrors(['role' => 'You cannot change your own role. Ask another superadmin.']);
            }

            if (!$this->canAssignRole($validated['role'])) {
                return back()->withErrors(['role' => 'Only a superadmin can grant the superadmin role.']);
            }

            if ($this->isLastSuperadmin($user)) {
                return back()->withErrors(['role' => 'This is the only superadmin account, so its role cannot be changed.']);
            }
        }

        $email = strtolower(trim($validated['email']));
        $phone = User::normalizePhone($validated['phone'] ?? null);

        if ($clash = $this->emailHolder($email, $user)) {
            return $this->duplicateBack($request, 'email', $clash);
        }

        if ($phone && $clash = $this->phoneHolder($phone, $user)) {
            return $this->duplicateBack($request, 'phone', $clash);
        }

        $trial = clone $user;
        $this->applyLoginWindow($trial, $request);

        if ($trial->outsideLoginWindow() && $user->id === $currentUser->id) {
            return back()->withErrors(['days' => 'That would block your own account right now, so it was not saved. Ask another superadmin to set your hours.']);
        }

        $oldData = $user->only(['name', 'email', 'phone', 'country', 'timezone', 'is_active', 'allowed_days', 'allowed_from', 'allowed_to']);

        $profile = [
            'name' => $validated['name'],
            'email' => $email,
            'phone' => $phone,
            'is_active' => $request->boolean('is_active'),
        ];

        // Country and clock zone are a superadmin's call; an admin editing the same
        // account cannot move a desk between the two countries by leaving a field blank.
        if ($currentUser->hasRole('superadmin') && ($request->filled('country') || $request->filled('timezone'))) {
            $country = $request->filled('country') ? $validated['country'] : $user->country;
            $profile['country'] = $country;
            $profile['timezone'] = $request->filled('timezone')
                ? $validated['timezone']
                : User::timezoneForCountry($country);
        }

        $user->update($profile);

        $this->applyLoginWindow($user, $request);
        $user->save();

        if (!empty($validated['password'])) {
            $this->writePassword($user, $validated['password']);

            ActivityLog::create([
                'user_id' => $currentUser->id,
                'action' => 'password_reset',
                'description' => "Password reset for user {$user->name} ({$user->email})",
                'old_value' => null,
                'new_value' => 'password_changed',
            ]);
        }

        if ($roleChanging) {
            $user->syncRoles([$validated['role']]);

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
            'new_value' => json_encode($user->only(['name', 'email', 'phone', 'country', 'timezone', 'is_active', 'allowed_days', 'allowed_from', 'allowed_to'])),
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
            'password' => 'required|'.self::PASSWORD_RULES.'|confirmed',
        ], self::PASSWORD_MESSAGES + [
            'password.required' => 'New password is required.',
        ]);

        $this->writePassword($user, $validated['password']);

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

        if (!$user->isLocked() && $this->isLastSuperadmin($user)) {
            return back()->withErrors(['error' => 'This is the only superadmin account, so it cannot be locked.']);
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

        if ($user->is_active && $this->isLastSuperadmin($user)) {
            return back()->withErrors(['error' => 'This is the only active superadmin account, so it cannot be deactivated.']);
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

    public function bulk(Request $request)
    {
        $validated = $request->validate([
            'action' => 'required|string|in:' . implode(',', array_keys(self::BULK_ACTIONS)),
            'user_ids' => 'required|array|min:1',
            'user_ids.*' => 'integer',
        ], [
            'action.required' => 'Choose a bulk action first.',
            'user_ids.required' => 'Tick at least one user to run a bulk action.',
        ]);

        $action = $validated['action'];
        $actor = auth()->user();
        $targets = User::with('roles')->whereIn('id', $validated['user_ids'])->get();

        $changed = 0;
        $skips = [];

        foreach ($targets as $user) {
            $reason = $this->bulkSkipReason($action, $user, $actor);

            if ($reason) {
                $skips[$reason] = ($skips[$reason] ?? 0) + 1;
                continue;
            }

            $this->applyBulkAction($action, $user, $actor);
            $changed++;
        }

        $message = $changed > 0
            ? self::BULK_ACTIONS[$action] . " {$changed} account(s)."
            : 'Nothing was changed.';

        if ($skips) {
            $parts = [];

            foreach ($skips as $reason => $count) {
                $parts[] = "{$count} {$reason}";
            }

            $message .= ' Skipped ' . implode(', ', $parts) . '.';
        }

        return $changed > 0
            ? back()->with('success', $message)
            : back()->withErrors(['error' => $message]);
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

        $transactionCount = $user->transactions()->withTrashed()->count();
        if ($transactionCount > 0) {
            return back()->withErrors([
                'error' => "This account has {$transactionCount} transaction(s) attached to it. Deleting it would erase those records and their audit trail. Deactivate it instead.",
            ]);
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

    private function assignableRoles()
    {
        if (auth()->user()->hasRole('superadmin')) {
            return Role::all();
        }

        return Role::where('name', '!=', 'superadmin')->get();
    }

    private function canAssignRole(string $role): bool
    {
        return $role !== 'superadmin' || auth()->user()->hasRole('superadmin');
    }

    private function isLastSuperadmin(User $user): bool
    {
        if (!$user->hasRole('superadmin')) {
            return false;
        }

        return User::where('is_active', true)
            ->whereHas('roles', fn($q) => $q->where('name', 'superadmin'))
            ->where('id', '!=', $user->id)
            ->doesntExist();
    }

    private function validateUser(Request $request, ?User $user = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:100', 'regex:' . self::NAME_PATTERN],
            'email' => 'required|email|max:100',
            'phone' => 'nullable|string|max:20',
            'password' => ($user ? 'nullable|' : 'required|') . self::PASSWORD_RULES . ($user ? '' : '|confirmed'),
            'role' => 'required|exists:roles,name',
            'country' => ['nullable', Rule::in(array_keys(User::COUNTRIES))],
            'timezone' => ['nullable', Rule::in(array_keys(Setting::TIMEZONES))],
            'days' => 'nullable|array',
            'days.*' => 'integer|min:1|max:7',
            'allowed_from' => 'nullable|date_format:H:i',
            'allowed_to' => 'nullable|date_format:H:i',
        ], self::PASSWORD_MESSAGES + [
            'name.required' => 'Full name is required.',
            'name.min' => 'Name must be at least 3 characters.',
            'name.regex' => self::NAME_MESSAGE,
            'email.required' => 'Email address is required.',
            'email.email' => 'Enter a valid email address.',
            'phone.max' => 'Enter a valid phone number.',
            'password.required' => 'Password is required.',
            'role.required' => 'Please select a role.',
            'role.exists' => 'Invalid role selected.',
            'country.in' => 'Choose Uganda or China.',
            'timezone.in' => 'Please choose one of the listed time zones.',
            'allowed_from.date_format' => 'Use the 24 hour format for the start time, such as 08:00.',
            'allowed_to.date_format' => 'Use the 24 hour format for the end time, such as 18:00.',
        ]);
    }

    private function emailHolder(string $email, ?User $ignore = null): ?User
    {
        return $this->holder('email', $email, $ignore);
    }

    private function phoneHolder(string $phone, ?User $ignore = null): ?User
    {
        return $this->holder('phone', $phone, $ignore);
    }

    private function holder(string $column, string $value, ?User $ignore): ?User
    {
        return User::where($column, $value)
            ->when($ignore, fn($q) => $q->where('id', '!=', $ignore->id))
            ->first();
    }

    private function duplicateBack(Request $request, string $field, User $holder)
    {
        $label = $field === 'email' ? 'email address' : 'phone number';

        return back()->withInput()->withErrors([
            $field => "That {$label} is already used by {$holder->name} ({$holder->email}). Each account needs its own.",
        ]);
    }

    private function applyLoginWindow(User $user, Request $request): void
    {
        $days = $request->input('days', []);

        if (!is_array($days)) {
            $days = [];
        }

        // Every day ticked means no day restriction at all.
        if (count($days) === 7) {
            $days = [];
        }

        $user->setAllowedDays($days);
        $user->allowed_from = $request->filled('allowed_from') ? substr($request->input('allowed_from'), 0, 5) : null;
        $user->allowed_to = $request->filled('allowed_to') ? substr($request->input('allowed_to'), 0, 5) : null;
    }

    /**
     * A new account starts in Uganda unless a superadmin sets the country; the clock
     * zone follows the country unless the superadmin overrides it.
     *
     * @return array{country: string, timezone: string}
     */
    private function countryAttributes(array $validated): array
    {
        $canSet = auth()->user()->hasRole('superadmin');

        $country = $canSet && !empty($validated['country'])
            ? $validated['country']
            : User::DEFAULT_COUNTRY;

        $timezone = $canSet && !empty($validated['timezone'])
            ? $validated['timezone']
            : User::timezoneForCountry($country);

        return ['country' => $country, 'timezone' => $timezone];
    }

    private function bulkSkipReason(string $action, User $user, User $actor): ?string
    {
        $changesAccess = in_array($action, ['deactivate', 'lock'], true);

        if ($user->hasRole('superadmin') && !$actor->hasRole('superadmin')) {
            return 'superadmin account(s) you cannot change';
        }

        if ($user->id === $actor->id) {
            if ($changesAccess) {
                return 'your own account';
            }

            if ($action === 'window_weekdays') {
                $trial = clone $user;
                $trial->allowed_days = '1,2,3,4,5';
                $trial->allowed_from = '08:00';
                $trial->allowed_to = '18:00';

                if ($trial->outsideLoginWindow()) {
                    return 'your own account, because those hours would block you now';
                }
            }
        }

        if ($changesAccess && $this->isLastSuperadmin($user)) {
            return 'the only active superadmin account';
        }

        return null;
    }

    private function applyBulkAction(string $action, User $user, User $actor): void
    {
        $changes = match ($action) {
            'activate' => ['is_active' => true],
            'deactivate' => ['is_active' => false],
            'lock' => ['is_locked' => true],
            'unlock' => ['is_locked' => false, 'failed_login_attempts' => 0, 'locked_until' => null],
            'window_weekdays' => ['allowed_days' => '1,2,3,4,5', 'allowed_from' => '08:00', 'allowed_to' => '18:00'],
            'window_clear' => ['allowed_days' => null, 'allowed_from' => null, 'allowed_to' => null],
            'two_factor_off' => ['two_factor_enabled' => false, 'two_factor_secret' => null],
        };

        $logAction = match ($action) {
            'activate' => 'user_activated',
            'deactivate' => 'user_deactivated',
            'lock' => 'locked',
            'unlock' => 'unlocked',
            'window_weekdays' => 'login_window_set',
            'window_clear' => 'login_window_cleared',
            'two_factor_off' => 'two_factor_disabled',
        };

        $old = $user->only(array_keys($changes));
        $user->update($changes);

        // The enrolment key must never land in the audit trail.
        unset($old['two_factor_secret'], $changes['two_factor_secret']);

        ActivityLog::create([
            'user_id' => $actor->id,
            'action' => $logAction,
            'description' => 'Bulk action: ' . self::BULK_ACTIONS[$action] . ' ' . $user->name . " ({$user->email})",
            'old_value' => json_encode($old),
            'new_value' => json_encode($changes),
        ]);
    }

    private function guardTarget(User $user): void
    {
        if ($user->hasRole('superadmin') && !auth()->user()->hasRole('superadmin')) {
            abort(403, 'Only superadmins can change two-factor settings on a superadmin account.');
        }
    }

    private function ensureSecret(User $user): void
    {
        if (!$user->two_factor_secret) {
            $user->update(['two_factor_secret' => TotpService::generateSecret()]);
        }
    }

    public function twoFactorForm(User $user)
    {
        $this->guardTarget($user);
        $this->ensureSecret($user);

        return view('users.two-factor', [
            'user' => $user,
            'secret' => $user->two_factor_secret,
            'provisioningUri' => TotpService::provisioningUri($user->two_factor_secret, $user->email),
        ]);
    }

    public function twoFactorEnable(Request $request, User $user)
    {
        $this->guardTarget($user);
        $this->ensureSecret($user);

        $validated = $request->validate([
            'code' => 'required|string|size:6',
        ], [
            'code.required' => 'Enter the 6-digit code shown in the authenticator app.',
            'code.size' => 'The code is 6 digits, with no spaces.',
        ]);

        $code = preg_replace('/\D/', '', $validated['code']);

        if (!TotpService::verify($user->two_factor_secret, $code)) {
            return back()->withErrors([
                'code' => 'That code is wrong or has expired. Wait for a new one and try again.',
            ]);
        }

        $user->update(['two_factor_enabled' => true]);

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'two_factor_enabled',
            'description' => "Two-factor sign-in turned on for user {$user->name} ({$user->email})",
            'new_value' => 'two_factor_on',
        ]);

        return redirect()->route('users.index')->with('success', "Two-factor sign-in is on for {$user->name}. They will be asked for a 6-digit code at every login.");
    }

    public function twoFactorReset(User $user)
    {
        $this->guardTarget($user);

        $user->update([
            'two_factor_secret' => TotpService::generateSecret(),
            'two_factor_enabled' => false,
        ]);

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'two_factor_reset',
            'description' => "Two-factor setup reset for user {$user->name}",
        ]);

        return back()->with('success', "A new setup key was generated for {$user->name}. Scan it again to finish turning 2FA on.");
    }

    public function twoFactorDisable(User $user)
    {
        $this->guardTarget($user);

        if (!$user->two_factor_enabled && !$user->two_factor_secret) {
            return back()->withErrors(['error' => "Two-factor sign-in is not set up for {$user->name}."]);
        }

        $user->update(['two_factor_enabled' => false, 'two_factor_secret' => null]);

        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'two_factor_disabled',
            'description' => "Two-factor sign-in turned off for user {$user->name} ({$user->email})",
        ]);

        return redirect()->route('users.index')->with('success', "Two-factor sign-in is off for {$user->name}. They can sign in with only their password.");
    }

    /**
     * Clearing the remember token stops a stolen "remember me" cookie from
     * outliving the password it was issued against.
     */
    private function writePassword(User $user, string $plain): void
    {
        $user->password = Hash::make($plain);
        $user->remember_token = null;
        $user->save();
    }
}
