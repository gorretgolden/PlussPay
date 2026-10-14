@php
    // An Admin sets the desk for everyone, but never for their own account, so nobody can
    // move themselves onto the China side to pick up a yuan wallet.
    $actor = auth()->user();
    $canSet = $actor->hasRole(['admin', 'superadmin']) && $actor->id !== ($user->id ?? null);
    $currentCountry = old('country', $country ?? \App\Models\User::DEFAULT_COUNTRY);
    $currentTimezone = old('timezone', $timezone ?? \App\Models\User::timezoneForCountry($currentCountry));
@endphp

@if($canSet)
<div class="mb-4">
    <label class="block text-sm font-medium text-gray-700 mb-1">Country</label>
    <select name="country"
        class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:border-green-600 focus:ring-2 focus:ring-green-600/30 outline-none transition">
        @foreach(\App\Models\User::COUNTRIES as $code => $label)
            <option value="{{ $code }}" @selected($currentCountry === $code)>{{ $label }}</option>
        @endforeach
    </select>
    <p class="text-xs text-gray-500 mt-1">Which desk this account works from. Uganda holds shillings and dollars, China holds yuan and dollars, so this is what decides the wallets they see when they sign in. It also sets their clock.</p>
    @error('country')<p class="text-red-500 text-sm mt-1">{{ $message }}</p>@enderror
</div>

<div class="mb-4">
    <label class="block text-sm font-medium text-gray-700 mb-1">Time zone</label>
    <select name="timezone"
        class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:border-green-600 focus:ring-2 focus:ring-green-600/30 outline-none transition">
        @foreach(\App\Models\Setting::TIMEZONES as $zone => $label)
            <option value="{{ $zone }}" @selected($currentTimezone === $zone)>{{ $label }}</option>
        @endforeach
    </select>
    <p class="text-xs text-gray-500 mt-1">Defaults to the country's clock. Change it only if this account should see a different time.</p>
    @error('timezone')<p class="text-red-500 text-sm mt-1">{{ $message }}</p>@enderror
</div>
@else
<div class="mb-4">
    <span class="block text-sm font-medium text-gray-700 mb-1">Country and clock</span>
    <div class="px-3 py-2 border border-gray-200 bg-gray-50 rounded-md text-sm text-gray-600">
        {{ \App\Models\User::COUNTRIES[$currentCountry] ?? 'Not set' }} · {{ \App\Models\Setting::TIMEZONES[$currentTimezone] ?? $currentTimezone }}
    </div>
    <p class="text-xs text-gray-500 mt-1">Locked on your own account, so a desk cannot be moved between countries by the person sitting at it. Another Admin or a Super Admin changes it.</p>
</div>
@endif
