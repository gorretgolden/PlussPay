@php
    $selectedDays = $selectedDays ?? [];
    $allowedFrom = $allowedFrom ?? null;
    $allowedTo = $allowedTo ?? null;
@endphp

<div class="mb-4">
    <div class="flex items-center justify-between mb-2">
        <label class="block text-sm font-medium text-gray-700">Login hours</label>
        <span class="text-xs text-gray-500">Restrict when this account may sign in</span>
    </div>

    <div class="border border-gray-200 rounded-md p-4 bg-gray-50">
        <div class="flex flex-wrap items-center gap-2 mb-3">
            @foreach(\App\Models\User::DAY_NAMES as $index => $label)
            <label class="flex items-center gap-1.5 px-2 py-1 bg-white border border-gray-300 rounded text-sm cursor-pointer">
                <input type="checkbox" name="days[]" value="{{ $index + 1 }}" class="login-day rounded border-gray-300 text-green-700"
                    {{ in_array($index + 1, $selectedDays) ? 'checked' : '' }}>
                {{ substr($label, 0, 3) }}
            </label>
            @endforeach
            <button type="button" onclick="setLoginDays([1,2,3,4,5])" class="text-xs text-green-800 hover:underline">Mon to Fri</button>
            <button type="button" onclick="setLoginDays([])" class="text-xs text-gray-600 hover:underline">Any day</button>
        </div>

        <div class="flex flex-wrap gap-4">
            <div>
                <label class="block text-xs text-gray-500 mb-1">From</label>
                <input type="time" name="allowed_from" value="{{ $allowedFrom }}"
                    class="px-3 py-2 border border-gray-300 rounded-md text-sm bg-white focus:border-green-600 focus:ring-2 focus:ring-green-600/30 outline-none">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">To</label>
                <input type="time" name="allowed_to" value="{{ $allowedTo }}"
                    class="px-3 py-2 border border-gray-300 rounded-md text-sm bg-white focus:border-green-600 focus:ring-2 focus:ring-green-600/30 outline-none">
            </div>
        </div>

        <p class="text-xs text-gray-500 mt-3">
            Leave the days unchecked and the times blank to allow signing in at any time. Times are 24 hour clock.
        </p>
    </div>

    @error('days')<p class="text-red-500 text-sm mt-1">{{ $message }}</p>@enderror
    @error('allowed_from')<p class="text-red-500 text-sm mt-1">{{ $message }}</p>@enderror
    @error('allowed_to')<p class="text-red-500 text-sm mt-1">{{ $message }}</p>@enderror
</div>

<script>
function setLoginDays(days) {
    document.querySelectorAll('.login-day').forEach(box => {
        box.checked = days.includes(Number(box.value));
    });
}

(function () {
    const nameField = document.querySelector('input[name="name"]');

    if (!nameField) {
        return;
    }

    nameField.addEventListener('blur', () => {
        nameField.value = nameField.value
            .trim()
            .replace(/\s+/g, ' ')
            .split(' ')
            .map(part => part.charAt(0).toUpperCase() + part.slice(1).toLowerCase())
            .join(' ');
    });
})();
</script>
