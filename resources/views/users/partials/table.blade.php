<div class="text-xs text-gray-500 mb-3" data-users-total="{{ $users->total() }}">{{ number_format($users->total()) }} {{ $users->total() === 1 ? 'account' : 'accounts' }} · {{ $users->firstItem() ?? 0 }} to {{ $users->lastItem() ?? 0 }} shown</div>
@can('edit-user')
<form id="bulkForm" method="POST" action="{{ route('users.bulk') }}" data-user-confirm data-bulk hidden class="bg-green-50 border border-green-200 rounded-lg p-4 mb-3">
    @csrf
    <div class="flex flex-wrap gap-3 items-center">
        <span id="bulkCount" class="text-sm font-semibold text-green-800" role="status"></span>
        <label for="bulkAction" class="text-sm">Action</label>
        <select id="bulkAction" name="action" required class="border border-gray-300 rounded-md px-3 py-2 text-sm bg-white">
            <option value="">Choose an action</option><option value="activate">Activate accounts</option><option value="deactivate">Deactivate accounts</option><option value="lock">Lock accounts</option><option value="unlock">Unlock accounts</option><option value="window_set">Set sign-in hours</option><option value="window_clear">Remove sign-in hours</option><option value="two_factor_off">Turn off 2FA</option>
        </select>
        <button type="submit" class="bg-green-800 text-white px-4 py-2 rounded-md text-sm">Apply to selected</button>
        <button type="button" data-clear-selection class="text-sm underline text-gray-600">Clear selection</button>
    </div>
    <fieldset id="bulk-window" hidden disabled class="mt-4">
        <legend class="text-sm mb-2">Choose allowed days and local times</legend>
        <div class="flex flex-wrap gap-2">@foreach(\App\Models\User::DAY_NAMES as $index => $label)<label for="bulk-day-{{ $index }}" class="user-day"><input id="bulk-day-{{ $index }}" type="checkbox" name="days[]" value="{{ $index + 1 }}">{{ substr($label, 0, 3) }}</label>@endforeach</div>
        <div class="user-grid mt-3"><div class="user-field"><label for="bulk-from">From</label><input id="bulk-from" name="allowed_from" type="time" required></div><div class="user-field"><label for="bulk-to">To</label><input id="bulk-to" name="allowed_to" type="time" required></div></div>
        <p class="user-help">Uses each selected account’s own time zone.</p>
    </fieldset>
    <p id="bulk-error" role="alert" class="user-error"></p>
</form>
@endcan
<div class="bg-white rounded-lg shadow overflow-x-auto">
<table class="user-table">
    <colgroup><col style="width:36px"><col style="width:24%"><col style="width:16%"><col style="width:16%"><col style="width:17%"><col><col style="width:96px"></colgroup>
    <thead><tr><th>@can('edit-user')<input id="bulkAll" type="checkbox" aria-label="Select all eligible users on this page">@endcan</th><th scope="col">Name / Email</th><th scope="col">Role / Status</th><th scope="col">Country / Zone</th><th scope="col">Sign-in / 2FA</th><th scope="col">Last login</th><th scope="col" class="user-actions">Actions</th></tr></thead>
    <tbody>
    @forelse($users as $user)
    @php $protected = $user->id === auth()->id() || ($user->hasRole('superadmin') && !auth()->user()->hasRole('superadmin')); $canManage = !$user->hasRole('superadmin') || auth()->user()->hasRole('superadmin'); @endphp
    <tr>
        <td>@can('edit-user')<input class="bulk-user" type="checkbox" name="user_ids[]" value="{{ $user->id }}" form="bulkForm" aria-label="Select {{ $user->name }}" @disabled($protected) @if($protected) title="Protected account" @endif>@endcan</td>
        <td><div class="font-semibold text-gray-900">{{ $user->name }} @if($user->id === auth()->id())<span class="text-xs text-gray-400">(you)</span>@endif</div><div class="text-gray-500 text-xs mt-1">{{ $user->email }}</div>@if($user->phone)<div class="text-gray-500 text-xs mt-1">{{ $user->phone }}</div>@endif</td>
        <td><div class="text-gray-800 mb-1">{{ \App\Http\Controllers\RolePermissionController::displayName($user->getRoleNames()->first() ?? 'none') }}</div><span class="user-badge {{ !$user->is_active ? 'bad' : ($user->isLocked() ? 'warn' : 'good') }}">{{ !$user->is_active ? 'Inactive' : ($user->isLocked() ? 'Locked' : 'Active') }}</span></td>
        <td><div>{{ $user->countryLabel() ?: 'Not set' }}</div><div class="text-xs text-gray-500 mt-1">{{ $user->timezone }}</div></td>
        <td><div class="text-xs {{ $user->hasLoginWindow() ? 'text-orange-700' : 'text-gray-500' }}">{{ $user->loginWindowSummary() }}</div><span class="user-badge mt-1 {{ $user->two_factor_enabled ? 'good' : 'warn' }}">{{ $user->two_factor_enabled ? '2FA on' : '2FA not enabled' }}</span></td>
        <td class="text-gray-500 text-xs">@if($user->last_login_at)<time datetime="{{ $user->last_login_at->toIso8601String() }}" title="{{ $user->last_login_at->format('Y-m-d H:i:s T') }}">{{ $user->last_login_at->diffForHumans() }}</time>@else Never @endif</td>
        <td class="user-actions">
            @if($canManage && (auth()->user()->can('edit-user') || auth()->user()->can('delete-user')))
            <button type="button" class="user-menu-button" data-user-menu="user-menu-{{ $user->id }}" aria-haspopup="true" aria-expanded="false" aria-controls="user-menu-{{ $user->id }}" aria-label="Actions for {{ $user->name }}">⋮</button>
            <div id="user-menu-{{ $user->id }}" class="user-menu" hidden>
                @can('edit-user')
                <a href="{{ route('users.edit', $user) }}">Edit user</a>
                <a href="{{ route('users.edit', $user) }}#password">Reset password</a>
                <form method="POST" action="{{ route('users.reset-password', $user) }}" data-user-confirm data-confirm-message="Email a set-password link to 1 account?">@csrf<input type="hidden" name="send_password_link" value="1"><button type="submit">Email password link</button></form>
                @if(!$protected)
                <form method="POST" action="{{ route('users.toggle-active', $user) }}" data-user-confirm data-confirm-message="{{ $user->is_active ? 'Deactivate' : 'Activate' }} 1 account: {{ $user->name }}?">@csrf @method('PATCH')<button type="submit" class="{{ $user->is_active ? 'danger' : '' }}">{{ $user->is_active ? 'Deactivate' : 'Activate' }}</button></form>
                <form method="POST" action="{{ route('users.toggle-lock', $user) }}" data-user-confirm data-confirm-message="{{ $user->isLocked() ? 'Unlock' : 'Lock' }} 1 account: {{ $user->name }}?">@csrf @method('PATCH')<button type="submit">{{ $user->isLocked() ? 'Unlock account' : 'Lock account' }}</button></form>
                @endif
                <a href="{{ route('users.two-factor', $user) }}">{{ $user->two_factor_enabled ? 'Manage 2FA' : 'Set up 2FA' }}</a>
                @if($user->two_factor_enabled)<form method="POST" action="{{ route('users.two-factor.disable', $user) }}" data-user-confirm data-confirm-message="Turn off 2FA for 1 account: {{ $user->name }}? This reduces sign-in protection.">@csrf @method('DELETE')<button type="submit" class="danger">Turn off 2FA</button></form>@endif
                @endcan
                @can('delete-user')@if(!$protected && !$user->hasRole('superadmin'))<form method="POST" action="{{ route('users.destroy', $user) }}" data-user-confirm data-confirm-message="Delete 1 account: {{ $user->name }}? This cannot be undone.">@csrf @method('DELETE')<button type="submit" class="danger">Delete user</button></form>@endif
                @endcan
            </div>
            @else<span class="text-xs text-gray-400">Protected</span>@endif
        </td>
    </tr>
    @empty
    <tr><td colspan="7" class="text-center py-12"><p class="font-semibold text-gray-700">No users match these filters</p><p class="text-sm text-gray-500 mt-2">Try another search or clear the filters.</p><a href="{{ route('users.index') }}" data-clear-users class="text-green-800 underline text-sm inline-block mt-4">Clear filters</a></td></tr>
    @endforelse
    </tbody>
</table>
</div>
<div class="mt-4" data-users-pagination>{{ $users->links() }}</div>
