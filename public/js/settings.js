(() => {
    const page = document.getElementById('settings-page');
    if (!page) return;
    const forms = Array.from(page.querySelectorAll('[data-settings-form]'));
    const tabs = Array.from(page.querySelectorAll('[data-settings-tab]'));
    const moneyPattern = /^(?:[0-9]+|[0-9]{1,3}(?:,[0-9]{3})+)(?:\.[0-9]+)?$/;
    const raw = (value) => value.replaceAll(',', '').trim();
    const controls = (form) => Array.from(form.querySelectorAll('[data-original]'));
    const comparable = (field, value) => field.hasAttribute('data-money') && raw(value) !== '' && Number.isFinite(Number(raw(value))) ? String(Number(raw(value))) : value;
    const changed = (field) => comparable(field, field.value) !== comparable(field, field.dataset.original);
    const dirty = (form) => controls(form).some(changed);
    const snapshot = (form) => JSON.stringify(controls(form).map((field) => field.value));
    let leaving = false;
    let confirming = false;

    function format(value) {
        const text = raw(String(value));
        if (!/^-?\d+(?:\.\d+)?$/.test(text)) return value;
        const [whole, decimal] = text.split('.');
        return whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',') + (decimal === undefined ? '' : `.${decimal}`);
    }

    function validateMoney(field) {
        const value = raw(field.value);
        let message = '';
        if (value && (!moneyPattern.test(field.value.trim()) || !Number.isFinite(Number(value)))) message = 'Enter a valid amount.';
        else if (value && Number(value) < Number(field.dataset.min)) message = `Enter ${format(field.dataset.min)} or more.`;
        else if (value && field.dataset.max && Number(value) > Number(field.dataset.max)) message = `Enter ${format(field.dataset.max)} or less.`;
        field.setCustomValidity(message);
        return message;
    }

    function effectivePair(currency) {
        const buyField = page.querySelector(`#usd_${currency}_buying`);
        const sellField = page.querySelector(`#usd_${currency}_selling`);
        const sellText = raw(sellField.value);
        if (!sellText || !buyField.checkValidity() || !sellField.checkValidity()) return null;
        const sell = Number(sellText);
        const buy = Number(raw(buyField.value) || buyField.dataset.original || sellText);
        return { buy, sell, buyField, sellField, margin: currency === 'ugx' ? sell - buy : buy - sell };
    }

    function updatePreviews() {
        ['ugx', 'rmb'].forEach((currency) => {
            const pair = effectivePair(currency);
            const output = page.querySelector(`[data-rate-pair="${currency}"] [data-margin]`);
            const margin = pair ? Number(pair.margin.toFixed(6)) : null;
            output.textContent = margin === null ? 'Margin: Enter a selling rate' : `Margin: ${margin > 0 ? '+' : ''}${format(margin)} ${currency.toUpperCase()}/USD`;
            output.classList.toggle('text-red-700', margin !== null && margin < 0);
            output.classList.toggle('text-green-800', margin !== null && margin >= 0);
        });
        const preview = page.querySelector('#receipt-preview');
        preview.textContent = `${page.querySelector('#receipt_prefix').value.trim() || 'PS'}-${preview.dataset.date}-0001`;
        const siteMode = page.querySelector('#site_mode');
        if (siteMode) page.querySelector('#testing-mode-warning').hidden = siteMode.value !== 'testing' || siteMode.querySelector('[value="testing"]').disabled;
    }

    function update(form, edited = false) {
        const isDirty = dirty(form);
        form.querySelector('[data-save]').disabled = !isDirty;
        if (edited || isDirty) form.querySelector('[data-save-status]').textContent = isDirty ? 'Unsaved changes' : 'No unsaved changes';
        updatePreviews();
    }

    function rateConfirmation() {
        const lines = [];
        const warnings = [];
        ['ugx', 'rmb'].forEach((currency) => {
            const pair = effectivePair(currency);
            if (!pair) return;
            let pairChanged = false;
            [[pair.buyField, pair.buy], [pair.sellField, pair.sell]].forEach(([field, next]) => {
                const previous = field.dataset.original;
                if (previous !== '' && Math.abs(Number(previous) - next) < 0.0000005) return;
                pairChanged = true;
                lines.push(`${field.dataset.label}: ${previous === '' ? 'Not set' : format(previous)} → ${format(next)} ${currency.toUpperCase()}/USD`);
                if (previous !== '' && Number(previous) > 0 && Math.abs((next - Number(previous)) / Number(previous)) > 0.05) {
                    warnings.push(`${field.dataset.label} changes by more than 5%.`);
                }
            });
            if (pairChanged && pair.margin < 0) warnings.push(`${currency.toUpperCase()} margin is negative (${format(Number(pair.margin.toFixed(6)))} ${currency.toUpperCase()}/USD). This pair would lose money.`);
        });
        return { message: [...lines, ...warnings].join('\n'), danger: warnings.length > 0 };
    }

    async function switchTab(tab) {
        if (confirming || tab.dataset.settingsTab === page.dataset.activeTab) return;
        const current = forms.find((form) => form.dataset.settingsForm === page.dataset.activeTab);
        if (dirty(current)) {
            confirming = true;
            try {
                if (!await window.askConfirm({ title: 'Unsaved changes', message: 'Your changes stay in this section. Save them before leaving this page.', confirmText: 'Switch tab' })) return;
            } finally { confirming = false; }
        }
        page.dataset.activeTab = tab.dataset.settingsTab;
        tabs.forEach((button) => {
            const selected = button === tab;
            button.setAttribute('aria-selected', String(selected));
            button.tabIndex = selected ? 0 : -1;
            page.querySelector(`#${button.getAttribute('aria-controls')}`).hidden = !selected;
        });
        tab.focus();
    }

    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => switchTab(tab));
        tab.addEventListener('keydown', (event) => {
            let next;
            if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
            if (event.key === 'ArrowLeft') next = (index - 1 + tabs.length) % tabs.length;
            if (event.key === 'Home') next = 0;
            if (event.key === 'End') next = tabs.length - 1;
            if (next === undefined) return;
            event.preventDefault();
            switchTab(tabs[next]);
        });
    });

    page.addEventListener('input', (event) => {
        const field = event.target;
        if (!field.matches('[data-original]')) return;
        if (field.hasAttribute('data-money')) validateMoney(field);
        update(field.form, true);
    });
    page.addEventListener('change', (event) => {
        if (event.target.matches('[data-original]')) update(event.target.form, true);
    });
    page.addEventListener('focusout', (event) => {
        const field = event.target;
        if (!field.matches('[data-money]')) return;
        const message = validateMoney(field);
        if (!message) field.value = format(field.value);
        if (message) window.formValidation?.setError(field, message);
    });

    forms.forEach((form) => {
        let approved = false;
        controls(form).filter((field) => field.hasAttribute('data-money')).forEach((field) => {
            if (!validateMoney(field)) field.value = format(field.value);
        });
        update(form);
        form.addEventListener('submit', async (event) => {
            if (event.defaultPrevented) return;
            if (approved) {
                leaving = true;
                queueMicrotask(() => { if (event.defaultPrevented) leaving = false; });
                return;
            }
            event.preventDefault();
            if (confirming || !dirty(form)) return;
            let valid = true;
            form.querySelectorAll('[data-money]').forEach((field) => {
                const message = validateMoney(field);
                if (message) { valid = false; window.formValidation?.setError(field, message); }
            });
            if (!valid || !form.reportValidity()) {
                controls(form).find((field) => !field.validity.valid)?.focus();
                return;
            }
            const before = snapshot(form);
            let options = null;
            if (form.dataset.settingsForm === 'rates') {
                const rateChanges = rateConfirmation();
                options = { title: 'Save rates?', message: rateChanges.message || 'No rate values will change. A blank selling rate leaves its pair unchanged.', confirmText: 'Save rates', danger: rateChanges.danger };
            } else if (form.dataset.settingsForm === 'datetime') {
                const field = form.elements.namedItem('timezone');
                const previous = Array.from(field.options).find((option) => option.value === field.dataset.original)?.textContent.trim() || field.dataset.original;
                options = { title: 'Change time zone?', message: `${previous} → ${field.selectedOptions[0].textContent.trim()}\nScreen dates, new receipts and report day boundaries will use this zone. Existing timestamps are not rewritten.`, confirmText: 'Save changes' };
            } else if (form.dataset.settingsForm === 'site') {
                options = { title: 'Change site mode?', message: form.elements.namedItem('site_mode').value === 'testing' ? 'Demo logins are visible on the sign-in page' : 'Demo logins will be hidden on the sign-in page.', confirmText: 'Save changes', danger: form.elements.namedItem('site_mode').value === 'testing' };
            }
            if (forms.some((other) => other !== form && dirty(other))) {
                options ||= { title: 'Save this section?', message: '', confirmText: 'Save changes' };
                options.message += '\nOther sections have unsaved changes. Saving this section reloads the page and discards those changes.';
                options.danger = true;
            }
            if (options) {
                confirming = true;
                try { if (!await window.askConfirm(options)) return; }
                finally { confirming = false; }
                if (snapshot(form) !== before) return;
            }
            form.querySelectorAll('[data-money]').forEach((field) => { field.value = raw(field.value); });
            approved = true;
            form.requestSubmit(event.submitter || form.querySelector('[data-save]'));
            approved = false;
        });
    });

    window.addEventListener('beforeunload', (event) => {
        if (leaving || !forms.some(dirty)) return;
        event.preventDefault();
        event.returnValue = '';
    });
    window.addEventListener('pageshow', () => {
        leaving = false;
        forms.forEach((form) => update(form));
    });
})();
