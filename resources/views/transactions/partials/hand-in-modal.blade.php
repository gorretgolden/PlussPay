@once
{{-- One transfer's cash goes up from here: the figures are printed from the booking, not typed,
     because they were fixed by what was taken in at the counter. The request is priced again from
     the book when it arrives, so a stale tab cannot hand in money that is not there. --}}
<div id="handInModal" class="hidden fixed inset-0 z-50 bg-gray-900/50 flex items-center justify-center p-4"
    role="dialog" aria-modal="true" aria-labelledby="handInModalTitle">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-md">
        <div class="flex items-start justify-between gap-4 border-b border-gray-100 px-5 py-4">
            <div class="flex items-center gap-3">
                <span class="inline-flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-full bg-green-50 text-green-800 ring-1 ring-inset ring-green-100" aria-hidden="true">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 16.5V9.75m0 0l3 3m-3-3l-3 3M6.75 19.5a4.5 4.5 0 01-1.41-8.775 5.25 5.25 0 0110.233-2.33 3 3 0 013.758 3.848A3.752 3.752 0 0118 19.5H6.75z"/></svg>
                </span>
                <div>
                    <h3 id="handInModalTitle" class="text-base font-semibold text-gray-900">Hand this cash in</h3>
                    <p id="handInModalReceipt" class="text-xs text-gray-500">-</p>
                </div>
            </div>
            <button type="button" onclick="closeHandInModal()" class="rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600" aria-label="Close">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <form method="POST" action="{{ route('cash-handovers.from-transactions') }}" data-lock data-lock-label="Sending...">
            @csrf
            <input type="hidden" id="handInModalTransactionId" name="transaction_ids[]" value="">

            <div class="px-5 py-4">
                <p id="handInModalRoute" class="text-sm font-medium text-gray-900">-</p>

                <dl class="mt-4 space-y-2.5 rounded-lg bg-gray-50 p-3.5 ring-1 ring-inset ring-gray-200 text-sm">
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Money traveling</dt>
                        <dd id="handInModalPrincipal" class="text-right font-semibold text-gray-900 tabular-nums">-</dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-3">
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Charge with it</dt>
                        <dd id="handInModalCharge" class="text-right font-semibold text-gray-900 tabular-nums">-</dd>
                    </div>
                    <div class="flex items-baseline justify-between gap-3 border-t border-gray-200 pt-2.5">
                        <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Collected at the counter</dt>
                        <dd id="handInModalCollected" class="text-right font-semibold text-gray-900 tabular-nums">-</dd>
                    </div>
                </dl>
                <p class="mt-2 text-[11px] leading-relaxed text-gray-500">
                    What this transfer was worth when it was booked. The charge rides up with the cash and stays with your lead; only the money traveling goes on to China later.
                </p>

                <label for="hand_in_super_agent" class="mt-4 block text-sm text-gray-700 mb-1">Handing to <span class="text-red-600">*</span></label>
                <select id="hand_in_super_agent" name="super_agent_id" class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:border-green-500 focus:ring-green-500">
                    <option value="">Choose the super agent</option>
                    @foreach($superAgents as $lead)
                    <option value="{{ $lead->id }}">{{ $lead->name }}</option>
                    @endforeach
                </select>

                <label for="hand_in_note" class="mt-3 block text-sm text-gray-700 mb-1">Note <span class="font-normal text-gray-400">(optional)</span></label>
                <input type="text" id="hand_in_note" name="note" maxlength="255" placeholder="e.g. Counter cash up to today"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md text-sm focus:border-green-500 focus:ring-green-500">
            </div>

            <div class="flex justify-end gap-3 border-t border-gray-100 px-5 py-4">
                <button type="button" onclick="closeHandInModal()" class="rounded-md bg-gray-100 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-200">Keep the cash</button>
                <button type="submit" class="rounded-md bg-green-800 px-4 py-2 text-sm font-semibold text-white hover:bg-green-700">Send for approval</button>
            </div>
        </form>
    </div>
</div>

<script>
function openHandInModal(trigger) {
    document.querySelectorAll('[data-row-menu]').forEach(menu => menu.classList.add('hidden'));
    document.getElementById('handInModalTransactionId').value = trigger.dataset.id;
    document.getElementById('handInModalReceipt').textContent = trigger.dataset.receipt;
    document.getElementById('handInModalRoute').textContent = trigger.dataset.route;
    document.getElementById('handInModalPrincipal').textContent = trigger.dataset.principal;
    document.getElementById('handInModalCharge').textContent = trigger.dataset.charge;
    document.getElementById('handInModalCollected').textContent = trigger.dataset.collected;
    document.getElementById('handInModal').classList.remove('hidden');
    document.getElementById('hand_in_super_agent').focus();
}

function closeHandInModal() {
    document.getElementById('handInModal').classList.add('hidden');
}

document.getElementById('handInModal').addEventListener('click', function (event) {
    if (event.target === this) closeHandInModal();
});

document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') closeHandInModal();
});
</script>
@endonce
