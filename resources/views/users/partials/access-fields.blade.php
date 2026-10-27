@php
    $selectedDays = $selectedDays ?? [];
    $allowedFrom = $allowedFrom ?? '';
    $allowedTo = $allowedTo ?? '';
    $restricted = (bool) old('restrict_hours', count($selectedDays) || $allowedFrom || $allowedTo);
    $allowedFromDisplay = $allowedFrom === '' ? '' : \App\Models\User::displayClock($allowedFrom);
    $allowedToDisplay = $allowedTo === '' ? '' : \App\Models\User::displayClock($allowedTo);
@endphp
<fieldset class="user-section">
    <legend class="font-semibold text-sm">Sign-in hours</legend>
    <input type="hidden" name="restrict_hours" value="0">
    <label class="user-check" for="restrict_hours"><input id="restrict_hours" name="restrict_hours" type="checkbox" value="1" @checked($restricted) aria-controls="login-window" aria-expanded="{{ $restricted ? 'true' : 'false' }}">Restrict sign-in hours</label>
    <div id="login-window" class="mt-4" @if(!$restricted) hidden @endif>
        <fieldset aria-describedby="error-days">
            <legend class="text-sm mb-2">Allowed days</legend>
            <div class="flex flex-wrap gap-2">
            @foreach(\App\Models\User::DAY_NAMES as $index => $label)
                <label class="user-day" for="day-{{ $index + 1 }}"><input id="day-{{ $index + 1 }}" type="checkbox" name="days[]" value="{{ $index + 1 }}" @checked(in_array($index + 1, $selectedDays))><span>{{ substr($label, 0, 3) }}</span></label>
            @endforeach
            </div>
            <p id="error-days" data-field-error="days" class="user-error">@error('days'){{ $message }}@enderror</p>
        </fieldset>
        <div class="user-grid mt-4">
            <div class="user-field"><label for="allowed_from">From</label><input id="allowed_from" name="allowed_from" type="text" class="user-control" value="{{ $allowedFromDisplay }}" placeholder="8:00 AM" autocomplete="off" aria-describedby="help-hours error-allowed_from"><p id="error-allowed_from" data-field-error="allowed_from" class="user-error">@error('allowed_from'){{ $message }}@enderror</p></div>
            <div class="user-field"><label for="allowed_to">To</label><input id="allowed_to" name="allowed_to" type="text" class="user-control" value="{{ $allowedToDisplay }}" placeholder="6:00 PM" autocomplete="off" aria-describedby="help-hours error-allowed_to"><p id="error-allowed_to" data-field-error="allowed_to" class="user-error">@error('allowed_to'){{ $message }}@enderror</p></div>
        </div>
        <p id="help-hours" class="user-help">Account’s local time, typed with AM or PM. Overnight windows are supported.</p>
    </div>
</fieldset>
