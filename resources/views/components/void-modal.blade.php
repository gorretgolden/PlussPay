@once
{{-- Undoing a money movement puts the accounts back where they were, so the reason is asked for
     and the amounts shown before it posts. Nothing is deleted: the ledger keeps both sides. --}}
@php
    $void ??= [];
    $void = array_merge([
        'subject' => 'exchange',
        'first' => 'They handed over',
        'second' => 'We handed back',
        'note' => 'Both accounts go back to where they were. The deal stays on the trail with this reason against it.',
    ], $void);
    $hasSecond = $void['second'] !== null;
@endphp
<div id="voidModal" class="hidden fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md p-6">
        <h3 class="text-lg font-semibold text-gray-900">Cancel this {{ $void['subject'] }}?</h3>
        <p class="text-sm text-gray-600 mt-1" id="voidModalDeal">-</p>

        <dl class="mt-4 space-y-1 text-sm">
            <div class="flex justify-between">
                <dt class="text-gray-500">{{ $void['first'] }}</dt>
                <dd class="text-gray-900 font-medium" id="voidModalGiven">-</dd>
            </div>
            @if($hasSecond)
            <div class="flex justify-between">
                <dt class="text-gray-500">{{ $void['second'] }}</dt>
                <dd class="text-gray-900 font-medium" id="voidModalReceived">-</dd>
            </div>
            @endif
            <p class="text-xs text-gray-500 pt-2">{{ $void['note'] }}</p>
        </dl>

        <form method="POST" id="voidModalForm" action="">
            @csrf

            <label for="voidModalReason" class="block text-sm font-medium text-gray-700 mt-4">
                Why is it being cancelled? <span class="text-red-600">*</span>
            </label>
            <textarea id="voidModalReason" name="reason" rows="3" maxlength="255" required
                placeholder="{{ $hasSecond ? 'Wrong rate given, customer changed their mind, counted wrongly...' : 'Entered by mistake, paid for something else, supplier sent it back...' }}"
                class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 text-sm"></textarea>

            <div class="flex justify-end gap-3 mt-5">
                <button type="button" onclick="closeVoidModal()" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-300">
                    Keep it as it is
                </button>
                <button type="submit" class="bg-red-600 text-white px-4 py-2 rounded-md text-sm hover:bg-red-700">
                    Confirm cancellation
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openVoidModal(trigger) {
    document.querySelectorAll('[data-row-menu]').forEach(menu => menu.classList.add('hidden'));
    document.getElementById('voidModalForm').action = trigger.dataset.action;
    document.getElementById('voidModalDeal').textContent = trigger.dataset.deal;
    document.getElementById('voidModalGiven').textContent = trigger.dataset.first;
    if (document.getElementById('voidModalReceived')) {
        document.getElementById('voidModalReceived').textContent = trigger.dataset.second;
    }
    document.getElementById('voidModal').classList.remove('hidden');
    document.getElementById('voidModalReason').value = '';
    document.getElementById('voidModalReason').focus();
}

function closeVoidModal() {
    document.getElementById('voidModal').classList.add('hidden');
}

document.getElementById('voidModal').addEventListener('click', function (event) {
    if (event.target === this) closeVoidModal();
});

document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') closeVoidModal();
});
</script>
@endonce
