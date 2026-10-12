@once
{{-- Cancel is the only status change that takes cash back out of the till, so it asks for
     a reason and shows the amount being returned before it posts. --}}
<div id="cancelModal" class="hidden fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md p-6">
        <h3 class="text-lg font-semibold text-gray-900">Cancel this transfer?</h3>
        <p class="text-sm text-gray-600 mt-1" id="cancelModalReceipt">-</p>

        <dl class="mt-4 space-y-1 text-sm">
            <div class="flex justify-between">
                <dt class="text-gray-500">Transfer</dt>
                <dd class="text-gray-900 font-medium" id="cancelModalAmount">-</dd>
            </div>
            <div class="flex justify-between">
                <dt class="text-gray-500">Service charge</dt>
                <dd class="text-gray-900 font-medium" id="cancelModalFee">-</dd>
            </div>
            <div class="flex justify-between border-t pt-1">
                <dt class="text-gray-500">Coming back out of the till</dt>
                <dd class="text-red-600 font-bold" id="cancelModalTotal">-</dd>
            </div>
        </dl>

        <form method="POST" id="cancelModalForm" action="">
            @csrf
            @method('PATCH')
            <input type="hidden" name="status" value="cancelled">

            <label for="cancelModalReason" class="block text-sm font-medium text-gray-700 mt-4">
                Why is it being cancelled? <span class="text-red-600">*</span>
            </label>
            <textarea id="cancelModalReason" name="reason" rows="3" maxlength="300" required
                placeholder="Cash not received, wrong receiver details, customer called it off..."
                class="mt-1 w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 text-sm"></textarea>

            <div class="flex justify-end gap-3 mt-5">
                <button type="button" onclick="closeCancelModal()" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-300">
                    Keep the transfer
                </button>
                <button type="submit" class="bg-red-600 text-white px-4 py-2 rounded-md text-sm hover:bg-red-700">
                    Confirm cancellation
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openCancelModal(trigger) {
    document.querySelectorAll('[data-row-menu]').forEach(menu => menu.classList.add('hidden'));
    document.getElementById('cancelModalForm').action = trigger.dataset.action;
    document.getElementById('cancelModalReceipt').textContent = trigger.dataset.receipt;
    document.getElementById('cancelModalAmount').textContent = trigger.dataset.amount;
    document.getElementById('cancelModalFee').textContent = trigger.dataset.fee;
    document.getElementById('cancelModalTotal').textContent = trigger.dataset.total;
    document.getElementById('cancelModal').classList.remove('hidden');
    document.getElementById('cancelModalReason').value = '';
    document.getElementById('cancelModalReason').focus();
}

function closeCancelModal() {
    document.getElementById('cancelModal').classList.add('hidden');
}

document.getElementById('cancelModal').addEventListener('click', function (event) {
    if (event.target === this) closeCancelModal();
});

document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') closeCancelModal();
});
</script>
@endonce
