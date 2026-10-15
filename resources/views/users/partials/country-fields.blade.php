@php
    $actor = auth()->user();
    $canSet = $actor->hasRole(['admin', 'superadmin']) && $actor->id !== ($user->id ?? null);
    $currentCountry = old('country', $country ?? \App\Models\User::DEFAULT_COUNTRY);
    $currentTimezone = old('timezone', $timezone ?? \App\Models\User::timezoneForCountry($currentCountry));
@endphp
@if($canSet)
<div class="user-field">
    <div class="flex items-center gap-2">
        <label for="country">Country</label>
        <span class="user-tooltip"><button type="button" aria-label="About country" aria-describedby="country-tip">?</button><span role="tooltip" id="country-tip">Which desk this account works from. Uganda uses shilling and dollar wallets; China uses yuan and dollar wallets.</span></span>
    </div>
    <select id="country" name="country" data-country-zones='@json(\App\Models\User::COUNTRY_TIMEZONES)' aria-describedby="error-country">
        @foreach(\App\Models\User::COUNTRIES as $code => $label)<option value="{{ $code }}" @selected($currentCountry === $code)>{{ $label }}</option>@endforeach
    </select>
    <p class="user-help">Sets the desk’s wallets and default clock.</p>
    <p id="error-country" data-field-error="country" class="user-error">@error('country'){{ $message }}@enderror</p>
</div>
<div class="user-field">
    <div class="flex items-center gap-2">
        <label for="timezone">Time zone</label>
        <span class="user-tooltip"><button type="button" aria-label="About time zone" aria-describedby="timezone-tip">?</button><span role="tooltip" id="timezone-tip">Sign-in hours use this account’s local time zone. Country selects the default; Change allows an override.</span></span>
    </div>
    <div class="flex items-center justify-between gap-2 mb-2"><span id="timezone-label" class="text-sm text-gray-600">{{ $currentTimezone }}</span><button type="button" id="change-timezone" aria-controls="timezone-control" aria-expanded="false" class="text-sm text-green-800 underline">Change</button></div>
    <div id="timezone-control" hidden>
        <select id="timezone" name="timezone" aria-describedby="error-timezone">
            @foreach(\App\Models\Setting::TIMEZONES as $zone => $label)<option value="{{ $zone }}" @selected($currentTimezone === $zone)>{{ $label }}</option>@endforeach
        </select>
    </div>
    <p class="user-help">Country sets the default time zone.</p>
    <p id="error-timezone" data-field-error="timezone" class="user-error">@error('timezone'){{ $message }}@enderror</p>
</div>
@else
<div class="user-field user-span">
    <span class="block font-medium text-sm">Country and clock</span>
    <p class="text-sm text-gray-600 mt-2">{{ \App\Models\User::COUNTRIES[$currentCountry] ?? 'Not set' }} · {{ $currentTimezone }}</p>
    <p class="user-help">Locked on your own account. Ask another admin.</p>
</div>
@endif
