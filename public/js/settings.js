(() => {
    const page = document.getElementById('settings-page');
    if (!page) return;
    const forms = Array.from(page.querySelectorAll('[data-settings-form]'));
    const tabs = Array.from(page.querySelectorAll('[data-settings-tab]'));
    const moneyPattern = /^(?:[0-9]+|[0-9]{1,3}(?:,[0-9]{3})+)(?:\.[0-9]+)?$/;
    const raw = (value) => value.replaceAll(',', '').trim();
    // The automation switch sits in the market card beside its own pull button, so it
    // carries a form attribute pointing at the rates form and has to be collected by hand.
    const controls = (form) => Array.from(form.querySelectorAll('[data-original]'))
        .concat(form.id ? Array.from(page.querySelectorAll(`[data-original][form="${form.id}"]`)) : []);
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

    // The two pairs earn in opposite directions: shillings on the way in, yuan on the way out to
    // China, so each card names the side that has to carry the higher figure.
    function pairProblems(currency) {
        const buyField = page.querySelector(`#usd_${currency}_buying`);
        const sellField = page.querySelector(`#usd_${currency}_selling`);
        const buy = raw(buyField.value);
        const sell = raw(sellField.value);
        if (buy && !sell) {
            return [[sellField, 'Enter a selling rate, or clear the buying rate to leave it unchanged.']];
        }
        if (!buy || !sell) return [];
        // Equal prices are allowed: a desk passing money at cost is a real thing she does. Only a
        // reversed spread is stopped, because that one quietly books a loss on every transfer.
        const inverted = currency === 'ugx' ? Number(sell) < Number(buy) : Number(buy) < Number(sell);
        if (!inverted) return [];
        // The card line below the margin already states the rule, so the field says what to type.
        const side = currency === 'ugx' ? 'Selling' : 'Buying';
        const floor = currency === 'ugx' ? format(buy) : format(sell);
        return [[currency === 'ugx' ? sellField : buyField, `${side} must be more than ${floor}.`]];
    }

    const appliedPairErrors = new Map();
    function applyPairError(field, message) {
        if (!message && !appliedPairErrors.has(field)) return 0;
        if (message) {
            window.formValidation?.setError(field, message);
            appliedPairErrors.set(field, message);
            return 1;
        }
        window.formValidation?.clearError(field);
        appliedPairErrors.delete(field);
        return 0;
    }

    function showPairErrors() {
        let blocked = 0;
        ['ugx', 'rmb'].forEach((currency) => {
            const problems = pairProblems(currency);
            const expected = new Map(problems);
            [page.querySelector(`#usd_${currency}_buying`), page.querySelector(`#usd_${currency}_selling`)].forEach((field) => {
                blocked += applyPairError(field, expected.get(field) || '');
            });
        });
        return blocked;
    }

    function updatePreviews() {
        ['ugx', 'rmb'].forEach((currency) => {
            const card = page.querySelector(`[data-rate-pair="${currency}"]`);
            const pair = effectivePair(currency);
            const output = card.querySelector('[data-margin]');
            const rule = card.querySelector('[data-margin-rule]');
            const margin = pair ? Number(pair.margin.toFixed(6)) : null;
            const short = margin !== null && margin < 0;
            const flat = margin !== null && margin === 0;
            output.textContent = margin === null ? 'Margin: Enter a selling rate' : `Margin: ${margin > 0 ? '+' : ''}${format(margin)} ${currency.toUpperCase()}/USD`;
            output.classList.toggle('text-red-700', short);
            output.classList.toggle('text-amber-700', flat);
            output.classList.toggle('text-green-800', !short && !flat);
            rule.textContent = short ? card.dataset.marginWarn : (flat ? card.dataset.marginFlat : rule.dataset.ok);
            rule.classList.toggle('text-red-700', short);
            rule.classList.toggle('text-amber-700', flat);
            rule.classList.toggle('font-medium', short || flat);
            rule.classList.toggle('text-gray-500', !short && !flat);
        });
        showPairErrors();
        const preview = page.querySelector('#receipt-preview');
        preview.textContent = `${page.querySelector('#receipt_prefix').value.trim() || 'PS'}-${preview.dataset.date}-0001`;
        const siteMode = page.querySelector('#site_mode');
        if (siteMode) page.querySelector('#testing-mode-warning').hidden = siteMode.value !== 'testing' || siteMode.querySelector('[value="testing"]').disabled;
    }

    function update(form, edited = false) {
        const isDirty = dirty(form);
        const status = form.querySelector('[data-save-status]');
        form.querySelector('[data-save]').disabled = !isDirty;
        if (edited || isDirty) status.textContent = isDirty ? 'Unsaved changes' : 'No unsaved changes';
        status.classList.toggle('text-amber-700', isDirty);
        status.classList.toggle('font-medium', isDirty);
        status.classList.toggle('text-gray-500', !isDirty);
        updatePreviews();
    }

    function rateConfirmation() {
        const sections = [];
        const warnings = [];
        let danger = false;
        const signed = (value) => `${value > 0 ? '+' : ''}${format(Number(value.toFixed(6)))}`;
        ['ugx', 'rmb'].forEach((currency) => {
            const pair = effectivePair(currency);
            if (!pair) return;
            const unit = currency.toUpperCase();
            const rows = [];
            let moved = false;
            [['Buying', pair.buyField, pair.buy], ['Selling', pair.sellField, pair.sell]].forEach(([side, field, next]) => {
                const previous = raw(field.dataset.original);
                if (previous !== '' && Math.abs(Number(previous) - next) < 0.0000005) return;
                moved = true;
                // A jump this big is usually a mistyped figure, so its size rides on the row it
                // belongs to rather than repeating itself as a separate sentence.
                let note = '';
                if (previous !== '' && Number(previous) > 0) {
                    const percent = (next - Number(previous)) / Number(previous) * 100;
                    if (Math.abs(percent) > 5) note = ` (${percent > 0 ? 'up' : 'down'} ${format(Math.round(Math.abs(percent) * 10) / 10)}%)`;
                }
                rows.push([side, `${previous === '' ? 'not set' : format(previous)} → ${format(next)}${note}`]);
            });
            if (!moved) return;
            const saved = [raw(pair.buyField.dataset.original), raw(pair.sellField.dataset.original)];
            const was = saved[0] !== '' && saved[1] !== ''
                ? signed(currency === 'ugx' ? Number(saved[1]) - Number(saved[0]) : Number(saved[0]) - Number(saved[1]))
                : 'not set';
            rows.push(['Margin', `${was} → ${signed(pair.margin)} ${unit}/USD`]);
            sections.push({ title: `USD → ${unit}`, rows });
            if (pair.margin < 0) {
                danger = true;
                warnings.push(`${unit} margin is negative (${signed(pair.margin)} ${unit}/USD). Every transfer through this pair loses money.`);
            } else if (pair.margin === 0) {
                warnings.push(`${unit} margin is zero. Both prices are the same, so this leg earns nothing.`);
            }
        });
        const auto = page.querySelector('#market_buy_auto');
        if (auto && changed(auto)) {
            sections.push({
                title: '',
                rows: [[labelFor(auto), `${display(auto, auto.dataset.original)} → ${display(auto, auto.value)}`]],
            });
        }
        return { sections, message: warnings.join('\n'), danger };
    }

    function sectionTitle(form) {
        const tab = page.querySelector(`[data-settings-tab="${form.dataset.settingsForm}"]`);
        return tab ? tab.textContent.trim() : 'Settings';
    }

    function labelFor(field) {
        if (field.dataset.label) return field.dataset.label;
        const label = field.id && page.querySelector(`label[for="${CSS.escape(field.id)}"]`);
        return (label?.textContent || field.name || '').replace(/\s+/g, ' ').trim();
    }

    // People chose from a list of words, so the summary repeats the words rather than the stored
    // code. An address prints line by line on a receipt, so its breaks show as slashes.
    function display(field, value) {
        if (field.type === 'file') {
            return field.files && field.files.length ? field.files[0].name : 'unchanged';
        }
        if (field.tagName === 'SELECT') {
            const option = Array.from(field.options).find((item) => item.value === value);
            return option ? option.textContent.trim() : value;
        }
        const text = value.replaceAll('\r', '').split('\n').map((line) => line.trim()).filter(Boolean).join(' / ');
        return field.hasAttribute('data-money') && text ? format(raw(text)) : text;
    }

    // The rates card has its own summary because a rate pair has a figure between the two boxes
    // that only makes sense when they are read together. Everything else is a list of boxes.
    function sectionConfirmation(form) {
        const rows = [];
        controls(form).filter(changed).forEach((field) => {
            // A password has no safe place on screen, so the summary only says that it moved.
            if (field.name === 'demo_password') {
                rows.push([labelFor(field), 'Changed (the value is not shown here)']);
                return;
            }
            const previous = display(field, field.dataset.original);
            const next = display(field, field.value);
            rows.push([labelFor(field), `${previous || 'not set'} → ${next || 'blank'}`]);
        });
        return [{ title: '', rows }];
    }

    // Every section asks before it saves, and every section shows the same thing: the boxes that
    // actually moved, then whatever the change goes on to affect.
    function confirmOptions(form) {
        let options;
        if (form.dataset.settingsForm === 'rates') {
            const rateChanges = rateConfirmation();
            const empty = !rateChanges.sections.length;
            options = {
                title: 'Save rates?',
                sections: rateChanges.sections,
                message: rateChanges.message || (empty ? 'No rate values will change. A blank selling rate leaves its pair unchanged.' : ''),
                confirmText: 'Save rates',
                danger: rateChanges.danger,
            };
        } else {
            const sections = sectionConfirmation(form);
            const mode = form.elements.namedItem('site_mode');
            if (form.dataset.settingsForm === 'datetime') {
                options = { title: 'Change time zone?', sections, message: 'Screen dates, new receipts and report day boundaries will use this zone. Existing timestamps are not rewritten.', confirmText: 'Save changes' };
            } else if (form.dataset.settingsForm === 'site' && mode && changed(mode)) {
                const testing = mode.value === 'testing';
                options = { title: 'Change site mode?', sections, message: testing ? 'Demo logins are visible on the sign-in page' : 'Demo logins will be hidden on the sign-in page.', confirmText: 'Save changes', danger: testing };
            } else {
                options = { title: `Save ${sectionTitle(form)}?`, sections, confirmText: 'Save changes' };
            }
        }
        return appendOtherWarning(options, form);
    }

    function appendOtherWarning(options, form) {
        if (!forms.some((other) => other !== form && dirty(other))) return options;
        options.message = [options.message, 'Other sections have unsaved changes. Saving this section reloads the page and discards those changes.'].filter(Boolean).join('\n');
        options.danger = true;
        return options;
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
        updatePreviews();
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
            const blockedPairs = form.dataset.settingsForm === 'rates' ? showPairErrors() : 0;
            if (!valid || blockedPairs || !form.reportValidity()) {
                controls(form).find((field) => !field.validity.valid || field.getAttribute('aria-invalid') === 'true')?.focus();
                return;
            }
            const before = snapshot(form);
            const options = confirmOptions(form);
            confirming = true;
            try { if (!await window.askConfirm(options)) return; }
            finally { confirming = false; }
            if (snapshot(form) !== before) return;
            form.querySelectorAll('[data-money]').forEach((field) => { field.value = raw(field.value); });
            approved = true;
            form.requestSubmit(event.submitter || form.querySelector('[data-save]'));
            approved = false;
        });
    });

    const pullForm = page.querySelector('form[action*="market-rates/pull"]');
    if (pullForm) {
        pullForm.addEventListener('submit', () => {
            const button = pullForm.querySelector('button[type="submit"]');
            if (!button) return;
            button.disabled = true;
            button.textContent = 'Pulling...';
        });
    }

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
