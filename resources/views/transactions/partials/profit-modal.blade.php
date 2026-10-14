@once
{{-- Profit is worked out at booking time from the rates on the sheet, so the dialog repeats
     those same steps. A figure that was never priced says n/a rather than a comfortable zero. --}}
<div id="profitModal" class="hidden fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-lg p-6 max-h-[90vh] overflow-y-auto">
        <h3 class="text-lg font-semibold text-gray-900">Profit breakdown</h3>
        <p class="text-sm text-gray-600 mt-1" id="profitModalReceipt">-</p>

        <dl class="mt-4 space-y-2 text-sm" id="profitModalSteps"></dl>

        <div class="flex justify-end mt-5">
            <button type="button" onclick="closeProfitModal()" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-md text-sm hover:bg-gray-300">
                Close
            </button>
        </div>
    </div>
</div>

<script>
function openProfitModal(trigger) {
    document.querySelectorAll('[data-row-menu]').forEach(menu => menu.classList.add('hidden'));
    document.getElementById('profitModalReceipt').textContent = trigger.dataset.receipt;

    let steps = [];
    try {
        steps = JSON.parse(trigger.dataset.steps);
    } catch (error) {
        steps = [{label: 'Breakdown', value: 'The figures for this transfer could not be read.', tone: 'muted'}];
    }

    const box = document.getElementById('profitModalSteps');
    box.innerHTML = '';
    const tone = {loss: 'text-red-600', muted: 'text-gray-400', plain: 'text-gray-900'};

    steps.forEach(function (step) {
        const row = document.createElement('div');
        row.className = step.label === 'Total profit' ? 'border-t pt-2 mt-2' : '';

        const label = document.createElement('dt');
        label.className = 'text-xs uppercase tracking-wide text-gray-500';
        label.textContent = step.label;

        const value = document.createElement('dd');
        value.className = (step.label === 'Total profit' ? 'font-semibold ' : '') + (tone[step.tone] || tone.plain);
        value.textContent = step.value;

        row.appendChild(label);
        row.appendChild(value);
        box.appendChild(row);
    });

    document.getElementById('profitModal').classList.remove('hidden');
}

function closeProfitModal() {
    document.getElementById('profitModal').classList.add('hidden');
}

document.getElementById('profitModal').addEventListener('click', function (event) {
    if (event.target === this) closeProfitModal();
});

document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') closeProfitModal();
});
</script>
@endonce
