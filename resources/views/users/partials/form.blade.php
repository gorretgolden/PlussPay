@php
    $editing = isset($user);
    $descriptions = ['superadmin' => 'Full access, including roles and system security.', 'admin' => 'Manages accounts, users and daily operations.', 'agent' => 'Handles customer transfers at their assigned desk.', 'accountant' => 'Reviews balances, expenses and financial reports.', 'staff' => 'Uses the tools granted to this role.'];
@endphp
<div class="user-grid">
    <div class="user-field">
        <label for="name">Full name</label>
        <input id="name" name="name" autocomplete="off" value="{{ old('name', $user->name ?? '') }}" required minlength="3" maxlength="100" pattern="[A-Za-z][A-Za-z.\-]*(\s+[A-Za-z][A-Za-z.\-]*)+" aria-describedby="help-name error-name">
        <p id="help-name" class="user-help">First and last name, like Gorret Golden.</p>
        <p id="error-name" data-field-error="name" class="user-error">@error('name'){{ $message }}@enderror</p>
    </div>
    <div class="user-field">
        <label for="email">Email</label>
        <input id="email" name="email" type="email" autocomplete="off" value="{{ old('email', $user->email ?? '') }}" required maxlength="100" data-unique aria-describedby="error-email status-email">
        <span id="status-email" data-availability-status="email" class="user-help" aria-live="polite"></span>
        <p id="error-email" data-field-error="email" class="user-error">@error('email'){{ $message }}@enderror</p>
    </div>
    <div class="user-field">
        <label for="phone">Phone <span class="text-gray-400">(optional)</span></label>
        <input id="phone" name="phone" type="tel" autocomplete="off" value="{{ old('phone', $user->phone ?? '') }}" maxlength="20" pattern="[+0-9() .\-]{8,20}" data-unique aria-describedby="help-phone error-phone status-phone">
        <p id="help-phone" class="user-help">Local or international; formatting is corrected.</p>
        <span id="status-phone" data-availability-status="phone" class="user-help" aria-live="polite"></span>
        <p id="error-phone" data-field-error="phone" class="user-error">@error('phone'){{ $message }}@enderror</p>
    </div>
    <div class="user-field">
        <label for="role">Role</label>
        <select id="role" name="role" required aria-describedby="role-description role-warning error-role">
            <option value="">Choose a role</option>
            @foreach($roles as $role)
            <option value="{{ $role->name }}" data-description="{{ $descriptions[$role->name] ?? 'Uses the permissions assigned to this role.' }}" data-privileged="{{ in_array($role->name, ['admin', 'superadmin']) ? 'true' : 'false' }}" @selected(old('role', $editing ? $user->getRoleNames()->first() : '') === $role->name)>{{ \App\Http\Controllers\RolePermissionController::displayName($role->name) }}</option>
            @endforeach
        </select>
        <p id="role-description" class="user-help"></p>
        <p id="role-warning" class="user-warning" hidden>Privileged access: review carefully before saving.</p>
        <p id="error-role" data-field-error="role" class="user-error">@error('role'){{ $message }}@enderror</p>
    </div>
    @include('users.partials.country-fields', ['country' => $user->country ?? null, 'timezone' => $user->timezone ?? null])
</div>
<fieldset class="user-section">
    <legend class="font-semibold text-sm">Password and security</legend>
    <label class="user-check mb-4" for="send_password_link">
        <input id="send_password_link" name="send_password_link" type="checkbox" value="1" @checked(old('send_password_link')) aria-controls="manual-password">
        Email a set-password link instead
    </label>
    <div id="manual-password">
        @include('users.partials.password-fields', ['passwordRequired' => !$editing])
        <button type="button" data-generate-password class="text-sm text-green-800 underline mt-2">Generate password</button>
        @if($editing)<p class="user-help">Leave blank to keep the current password.</p>@endif
    </div>
    <input type="hidden" name="must_change_password" value="0">
    <label class="user-check mt-4" for="must_change_password">
        <input id="must_change_password" name="must_change_password" type="checkbox" value="1" @checked(old('must_change_password', $user->must_change_password ?? false)) @disabled($editing && $user->must_change_password)>
        Require password change at next sign-in
    </label>
    <p class="user-help">Required changes cannot be waived by administrators.</p>
    <p id="error-delivery" data-field-error="delivery" class="user-error">@error('delivery'){{ $message }}@enderror</p>
    <div class="flex flex-wrap items-center gap-3 mt-4">
        @if($editing && $user->two_factor_enabled)
        <span class="rounded-full bg-green-100 text-green-800 px-3 py-1 text-xs">2FA enabled</span>
        @else
        <span class="rounded-full bg-yellow-100 text-yellow-800 px-3 py-1 text-xs">2FA not enabled (recommended)</span>
        @endif
        @if($editing)
        <a class="text-sm text-green-800 underline" href="{{ route('users.two-factor', $user) }}">{{ $user->two_factor_enabled ? 'Manage 2FA' : 'Set up 2FA' }}</a>
        @else
        <span class="user-help">Set up after creating the account.</span>
        @endif
    </div>
</fieldset>
@include('users.partials.access-fields', ['selectedDays' => old('days', $editing ? $user->allowedDayList() : []), 'allowedFrom' => old('allowed_from', $user->allowed_from ?? ''), 'allowedTo' => old('allowed_to', $user->allowed_to ?? '')])
@if($editing)
<div class="mt-5">
    <input type="hidden" name="is_active" value="{{ $user->id === auth()->id() ? 1 : 0 }}">
    <label for="is_active" class="user-check"><input id="is_active" name="is_active" type="checkbox" value="1" @checked(old('is_active', $user->is_active)) @disabled($user->id === auth()->id()) aria-describedby="error-is_active">Account active</label>
    <p class="user-help">Inactive accounts cannot sign in.</p>
    <p id="error-is_active" data-field-error="is_active" class="user-error">@error('is_active'){{ $message }}@enderror</p>
</div>
@endif
