@php
    $canSetCountry = auth()->user()->hasRole('superadmin');
    $currentCountry = old('country', $country ?? \App\Models\User::DEFAULT_COUNTRY);
    $currentTimezone = old('timezone', $timezone ?? \App\Models\User::timezoneForCountry($currentCountry));
@endphp

@if($canSetCountry)
<div class="mb-4">
    <label class="block text-sm font-medium text-gray-700 mb-1">Country</label>
    <select name="country"
        class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:border-green-600 focus:ring-2 focus:ring-green-600/30 outline-none transition">
        @foreach(\App\Models\User::COUNTRIES as $code => $label)
            <option value="{{ $code }}" @selected($currentCountry === $code)>{{ $label }}</option>
        @endforeach
    </select>
    <p class="text-xs text-gray-500 mt-1">Tells the team which desk this account works from, so a China agent can see they are on the China side. Only a Super Admin can set or change it.</p>
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
@endif
