(() => {
    'use strict';
    const ready = (fn) => document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', () => queueMicrotask(fn)) : queueMicrotask(fn);
    const one = (selector, root = document) => root.querySelector(selector);
    const all = (selector, root = document) => Array.from(root.querySelectorAll(selector));
    const fallback = {
        setError(input, message) {
            const error = one(`[data-field-error="${input.name.replace('[]', '')}"]`, input.form);
            input.setAttribute('aria-invalid', 'true');
            if (error) {
                error.textContent = message;
                const ids = new Set((input.getAttribute('aria-describedby') || '').split(' ').filter(Boolean));
                ids.add(error.id);
                input.setAttribute('aria-describedby', [...ids].join(' '));
            }
        },
        clearError(input) {
            input.removeAttribute('aria-invalid');
            input.setCustomValidity('');
            const error = one(`[data-field-error="${input.name.replace('[]', '')}"]`, input.form);
            if (error) error.textContent = '';
        },
        validateField(input) {
            if (input.disabled || input.type === 'hidden') return true;
            this.clearError(input);
            if (!input.validity.valid) {
                this.setError(input, input.validationMessage);
                return false;
            }
            return true;
        },
        summary(form) {
            let summary = one('[data-validation-summary]', form);
            if (!summary) {
                summary = document.createElement('p');
                summary.dataset.validationSummary = '';
                summary.setAttribute('role', 'alert');
                summary.className = 'user-summary';
                form.prepend(summary);
            }
            const count = all('[data-field-error]', form).filter(el => el.textContent.trim()).length;
            // The slot keeps its height whether or not there is a message, so the fields never move under a click.
            summary.textContent = count ? `Please correct ${count} ${count === 1 ? 'field' : 'fields'} below.` : '';
        },
        initialize(form) { form.noValidate = true; }
    };

    ready(() => {
        const validation = window.formValidation || fallback;
        all('.user-management form[data-validate]').forEach(form => {
            validation.initialize(form);
            const password = one('#password', form);
            const confirmation = one('#password_confirmation', form);
            const invitation = one('#send_password_link', form);
            const restrict = one('#restrict_hours', form);
            const availability = new Map();
            const controls = () => all('input:not([type=hidden]),select,textarea', form);
            let approved = false;
            let submitting = false;

            function checkConfirmation() {
                if (!confirmation || confirmation.disabled) return;
                if (confirmation.value && confirmation.value !== password.value) validation.setError(confirmation, 'Passwords do not match.');
            }
            function refreshStrength() {
                if (!password) return;
                const value = password.value;
                const score = value ? Math.min(4, Number(value.length >= 8) + Number(value.length >= 12) + Number(/[a-z]/.test(value) && /[A-Z]/.test(value)) + Number(/[0-9]/.test(value) && /[^a-zA-Z0-9]/.test(value))) : 0;
                one('#password-strength', form).value = score;
                one('#strength-label', form).textContent = value ? `Strength: ${['Very weak', 'Weak', 'Fair', 'Good', 'Strong'][score]}` : 'Password strength';
                if (confirmation) confirmation.required = !confirmation.disabled && (!!value || form.dataset.creating === 'true' || !form.hasAttribute('data-user-form'));
            }
            function syncInvitation() {
                if (!invitation) return;
                one('#manual-password', form).hidden = invitation.checked;
                [password, confirmation].forEach(input => {
                    input.disabled = invitation.checked;
                    if (invitation.checked) { input.value = ''; validation.clearError(input); }
                    input.required = !invitation.checked && form.dataset.creating === 'true';
                });
                refreshStrength();
            }
            function syncHours() {
                if (!restrict) return;
                const panel = one('#login-window', form);
                panel.hidden = !restrict.checked;
                restrict.setAttribute('aria-expanded', String(restrict.checked));
                all('input', panel).forEach(input => {
                    input.disabled = !restrict.checked;
                    if (input.type === 'time') input.required = restrict.checked;
                    if (!restrict.checked) validation.clearError(input);
                });
            }
            function customValidation() {
                checkConfirmation();
                if (restrict?.checked) {
                    const day = one('[name="days[]"]', form);
                    if (!all('[name="days[]"]:checked', form).length) validation.setError(day, 'Choose at least one day.');
                    if (one('#allowed_from', form).value && one('#allowed_from', form).value === one('#allowed_to', form).value) validation.setError(one('#allowed_to', form), 'Choose different start and end times.');
                }
            }

            all('[data-toggle-password]', form).forEach(button => button.addEventListener('click', () => {
                const input = document.getElementById(button.dataset.togglePassword);
                const show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                button.textContent = show ? 'Hide' : 'Show';
                button.setAttribute('aria-pressed', String(show));
                button.setAttribute('aria-label', `${show ? 'Hide' : 'Show'} ${input === confirmation ? 'confirmation password' : 'password'}`);
            }));
            one('[data-generate-password]', form)?.addEventListener('click', () => {
                if (!window.crypto?.getRandomValues) return;
                // Rejection sampling avoids modulo bias; every class is represented.
                const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%*?';
                let value;
                do {
                    value = '';
                    while (value.length < 20) {
                        const bytes = crypto.getRandomValues(new Uint8Array(32));
                        for (const byte of bytes) if (byte < 256 - (256 % alphabet.length) && value.length < 20) value += alphabet[byte % alphabet.length];
                    }
                } while (!/[A-Z]/.test(value) || !/[a-z]/.test(value) || !/[0-9]/.test(value) || !/[^a-zA-Z0-9]/.test(value));
                password.value = confirmation.value = value;
                [password, confirmation].forEach(input => { input.dispatchEvent(new Event('input', { bubbles: true })); validation.clearError(input); });
                one('#strength-label', form).textContent = 'Strong password generated. Use Show to view.';
            });
            invitation?.addEventListener('change', syncInvitation);
            restrict?.addEventListener('change', syncHours);
            syncInvitation(); syncHours(); refreshStrength();
            const role = one('#role', form);
            const syncRole = () => {
                const option = role?.selectedOptions[0];
                if (!role) return;
                one('#role-description', form).textContent = option?.dataset.description || 'Choose the access this account needs.';
                one('#role-warning', form).hidden = option?.dataset.privileged !== 'true';
            };
            role?.addEventListener('change', syncRole); syncRole();
            const country = one('#country', form), zone = one('#timezone', form), zoneControl = one('#timezone-control', form), zoneButton = one('#change-timezone', form);
            const zoneText = () => zone.selectedOptions[0]?.textContent.trim() || zone.value;
            country?.addEventListener('change', () => {
                zone.value = JSON.parse(country.dataset.countryZones)[country.value];
                one('#timezone-label', form).textContent = zoneText();
                zoneControl.hidden = true; zoneButton.setAttribute('aria-expanded', 'false'); validation.clearError(zone);
            });
            zone?.addEventListener('change', () => { one('#timezone-label', form).textContent = zoneText(); });
            zoneButton?.addEventListener('click', () => {
                zoneControl.hidden = !zoneControl.hidden;
                zoneButton.setAttribute('aria-expanded', String(!zoneControl.hidden));
                if (!zoneControl.hidden) (zone.parentElement.querySelector('button') || zone).focus();
            });

            async function checkAvailability(input) {
                if (!form.dataset.availabilityUrl || input.disabled) return true;
                let state = availability.get(input);
                if (!state) { state = { version: 0 }; availability.set(input, state); }
                clearTimeout(state.timer);
                state.controller?.abort();
                const version = ++state.version;
                const value = input.value.trim();
                const status = one(`[data-availability-status="${input.name}"]`, form);
                if (!value || !input.validity.valid) { status.textContent = ''; return true; }
                state.controller = new AbortController();
                status.replaceChildren();
                const spinner = document.createElement('span'); spinner.className = 'user-spinner'; spinner.setAttribute('aria-hidden', 'true');
                status.append(spinner, 'Checking availability…');
                const url = new URL(form.dataset.availabilityUrl, location.origin);
                url.searchParams.set('field', input.name); url.searchParams.set(input.name, value);
                if (form.dataset.userId) url.searchParams.set('ignore_id', form.dataset.userId);
                try {
                    const response = await fetch(url, { headers: { Accept: 'application/json' }, signal: state.controller.signal, credentials: 'same-origin', cache: 'no-store' });
                    const data = await response.json();
                    if (state.version !== version || input.value.trim() !== value) return false;
                    status.replaceChildren();
                    if (data.errors?.[input.name]) {
                        validation.setError(input, [].concat(data.errors[input.name])[0]); return false;
                    }
                    if (!response.ok || typeof data.available !== 'boolean') throw new Error('Unavailable');
                    if (!data.available) { validation.setError(input, `This ${input.name} is already registered.`); return false; }
                    validation.clearError(input);
                    const tick = document.createElement('span'); tick.setAttribute('aria-hidden', 'true'); tick.textContent = '✓ ';
                    status.append(tick, 'Available');
                    return true;
                } catch (error) {
                    if (error.name === 'AbortError') return false;
                    if (state.version === version) status.textContent = 'Availability checked when you save.';
                    return true; // Final server validation remains authoritative.
                }
            }
            controls().forEach(input => {
                input.addEventListener('input', () => {
                    validation.clearError(input);
                    if (input === password) { refreshStrength(); if (confirmation) validation.clearError(confirmation); }
                    if (input.hasAttribute('data-unique')) {
                        const state = availability.get(input) || { version: 0 };
                        state.controller?.abort(); clearTimeout(state.timer); state.version++;
                        one(`[data-availability-status="${input.name}"]`, form).textContent = '';
                        state.timer = setTimeout(() => checkAvailability(input), 500);
                        availability.set(input, state);
                    }
                    validation.summary(form);
                });
                input.addEventListener('blur', () => {
                    if (input.name === 'name') input.value = input.value.trim().replace(/\s+/g, ' ').replace(/\b\w+\b/g, part => part[0].toUpperCase() + part.slice(1).toLowerCase());
                    if (input.name === 'email') input.value = input.value.trim().toLowerCase();
                    validation.validateField(input);
                    if (input === confirmation) checkConfirmation();
                    if (input.hasAttribute('data-unique')) checkAvailability(input);
                });
            });
            // Populate inline server errors even when the shared validator is unavailable.
            all('[data-field-error]', form).forEach(error => {
                if (!error.textContent.trim()) return;
                const input = controls().find(item => item.name.replace('[]', '') === error.dataset.fieldError);
                if (input) validation.setError(input, error.textContent.trim());
            });
            validation.summary(form);

            const enrollment = form.hasAttribute('data-password-enroll');
            if (enrollment) {
                const fragment = new URLSearchParams(location.hash.slice(1));
                one('#enrollment-token', form).value = fragment.get('token') || '';
                one('#email', form).value = fragment.get('email') || '';
                history.replaceState(null, '', location.pathname);
                if (!one('#enrollment-token', form).value) one('#enrollment-notice', form).textContent = 'Open the original email link to set your password.';
            }
            form.addEventListener('submit', async event => {
                if (approved) { approved = false; return; }
                event.preventDefault(); event.stopImmediatePropagation();
                if (submitting) return;
                controls().forEach(input => validation.validateField(input));
                customValidation();
                const focusInvalid = () => { validation.summary(form); const invalid = one('[aria-invalid="true"]:not(:disabled)', form); if (invalid) { invalid.closest('[hidden]')?.removeAttribute('hidden'); invalid.focus(); } return !!invalid; };
                if (focusInvalid()) return;
                submitting = true;
                const submitter = event.submitter;
                const original = submitter?.textContent;
                if (submitter) { submitter.disabled = true; submitter.textContent = 'Checking…'; }
                await Promise.all(all('[data-unique]', form).map(checkAvailability));
                if (submitter) { submitter.disabled = false; submitter.textContent = original; }
                submitting = false;
                if (focusInvalid()) return;
                if (enrollment) {
                    if (!one('#enrollment-token', form).value) { one('#enrollment-notice', form).textContent = 'Open the original email link to continue.'; return; }
                    submitting = true;
                    if (submitter) { submitter.disabled = true; submitter.textContent = 'Saving…'; }
                    try {
                        const response = await fetch(form.action, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                        const data = await response.json();
                        if (response.ok && data.redirect) { location.assign(data.redirect); return; }
                        Object.entries(data.errors || {}).forEach(([name, messages]) => { const input = form.elements.namedItem(name); if (input?.type !== 'hidden' && input) validation.setError(input, [].concat(messages)[0]); });
                        one('#enrollment-notice', form).textContent = data.message || 'Check the fields or request another link.';
                        focusInvalid();
                    } catch { one('#enrollment-notice', form).textContent = 'Could not save. Check your connection and retry.'; }
                    submitting = false;
                    if (submitter) { submitter.disabled = false; submitter.textContent = original; }
                    return;
                }
                approved = true;
                form.requestSubmit(submitter || undefined);
            }, true);
        });

        const directory = one('[data-users-directory]');
        if (!directory) return;
        const filter = one('#user-filters');
        const results = one('#users-results');
        const loading = one('#users-loading');
        const status = one('#users-request-status');
        let fetchController, searchTimer, requestVersion = 0;
        function closeMenus(focus = false) {
            all('[data-user-menu]', directory).forEach(button => { if (button.getAttribute('aria-expanded') === 'true' && focus) button.focus(); button.setAttribute('aria-expanded', 'false'); });
            all('.user-menu', directory).forEach(menu => menu.hidden = true);
        }
        function updateSelection() {
            const boxes = all('.bulk-user:not(:disabled)', results), selected = boxes.filter(box => box.checked);
            const bar = one('#bulkForm', results), master = one('#bulkAll', results);
            if (!bar) return;
            bar.hidden = selected.length === 0;
            one('#bulkCount', results).textContent = `${selected.length} ${selected.length === 1 ? 'account' : 'accounts'} selected`;
            master.checked = boxes.length > 0 && boxes.every(box => box.checked);
            master.indeterminate = selected.length > 0 && selected.length < boxes.length;
        }
        async function loadUsers(url, push = true) {
            closeMenus(); fetchController?.abort(); fetchController = new AbortController();
            const version = ++requestVersion;
            results.hidden = true; results.setAttribute('aria-busy', 'true'); loading.hidden = false; status.textContent = 'Loading users…';
            try {
                const response = await fetch(url, { headers: { 'X-Users-Partial': '1', Accept: 'text/html' }, credentials: 'same-origin', signal: fetchController.signal, cache: 'no-store' });
                if (response.redirected) { location.assign(response.url); return; }
                if (!response.ok) throw new Error('Load failed');
                const html = await response.text();
                if (version !== requestVersion) return;
                results.innerHTML = html;
                if (push) history.pushState(null, '', url);
                const query = new URL(url, location.origin).search;
                const exportLink = one('[data-users-export]');
                if (exportLink) { const exportUrl = new URL(exportLink.href); exportUrl.search = query; exportLink.href = exportUrl.href; }
                status.textContent = `${one('[data-users-total]', results)?.dataset.usersTotal || 0} accounts found.`;
                updateSelection();
            } catch (error) {
                if (error.name !== 'AbortError') status.textContent = 'Could not load users. Your previous results are shown. Try Search again.';
            } finally {
                if (version === requestVersion) { results.hidden = false; results.removeAttribute('aria-busy'); loading.hidden = true; }
            }
        }
        function search() {
            clearTimeout(searchTimer);
            const url = new URL(filter.action);
            new FormData(filter).forEach((value, key) => { if (value) url.searchParams.set(key, value); });
            loadUsers(url.href);
        }
        filter.addEventListener('submit', event => { event.preventDefault(); search(); });
        one('[name=search]', filter).addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(search, 400); });
        all('select', filter).forEach(select => select.addEventListener('change', search));
        directory.addEventListener('change', event => {
            if (event.target.id === 'bulkAll') all('.bulk-user:not(:disabled)', results).forEach(box => box.checked = event.target.checked);
            if (event.target.matches('.bulk-user,#bulkAll')) updateSelection();
            if (event.target.id === 'bulkAction') { const windowFields = one('#bulk-window', results); windowFields.hidden = windowFields.disabled = event.target.value !== 'window_set'; }
        });
        directory.addEventListener('click', event => {
            const clear = event.target.closest('[data-clear-users]');
            if (clear) { event.preventDefault(); all('input:not([type=hidden]),select', filter).forEach(input => input.value = ''); search(); return; }
            const page = event.target.closest('[data-users-pagination] a');
            if (page) { event.preventDefault(); clearTimeout(searchTimer); loadUsers(page.href); return; }
            if (event.target.closest('[data-clear-selection]')) { all('.bulk-user', results).forEach(box => box.checked = false); updateSelection(); }
            const trigger = event.target.closest('[data-user-menu]');
            if (trigger) {
                const open = trigger.getAttribute('aria-expanded') !== 'true'; closeMenus(); if (!open) return;
                const menu = document.getElementById(trigger.dataset.userMenu); menu.hidden = false;
                const rect = trigger.getBoundingClientRect();
                menu.style.left = `${Math.max(8, Math.min(innerWidth - 208, rect.right - 200))}px`;
                menu.style.top = `${Math.max(8, Math.min(innerHeight - menu.offsetHeight - 8, rect.bottom + 4))}px`;
                trigger.setAttribute('aria-expanded', 'true'); one('a,button', menu)?.focus();
            }
        });
        document.addEventListener('click', event => { if (!event.target.closest('[data-user-menu],.user-menu')) closeMenus(); });
        document.addEventListener('keydown', event => { if (event.key === 'Escape') closeMenus(true); });
        window.addEventListener('scroll', () => closeMenus(), true);
        window.addEventListener('resize', () => closeMenus());
        window.addEventListener('popstate', () => {
            const params = new URLSearchParams(location.search);
            all('input[name],select[name]', filter).forEach(input => input.value = params.get(input.name) || '');
            loadUsers(location.href, false);
        });
        directory.addEventListener('submit', async event => {
            const form = event.target.closest('[data-user-confirm]');
            if (!form || form.dataset.confirmed === 'true') return;
            event.preventDefault(); event.stopImmediatePropagation();
            if (form.dataset.confirming === 'true') return;
            let message = form.dataset.confirmMessage;
            if (form.hasAttribute('data-bulk')) {
                const selected = all('.bulk-user:checked:not(:disabled)', results).length;
                const action = one('#bulkAction', results);
                const error = one('#bulk-error', form); error.textContent = '';
                if (!selected || !action.value) { error.textContent = 'Select accounts and choose an action.'; return; }
                if (action.value === 'window_set' && !all('[name="days[]"]:checked', form).length) { error.textContent = 'Choose at least one allowed day.'; one('[name="days[]"]', form).focus(); return; }
                if (action.value === 'window_set' && one('#bulk-from', form).value === one('#bulk-to', form).value) { error.textContent = 'Choose different start and end times.'; return; }
                message = `${action.selectedOptions[0].text} for ${selected} selected ${selected === 1 ? 'account' : 'accounts'}? Protected accounts are skipped.`;
            }
            form.dataset.confirming = 'true';
            const accepted = window.askConfirm ? await window.askConfirm({ title: 'Confirm account changes', message, confirmText: 'Confirm', danger: true }) : window.confirm(message);
            delete form.dataset.confirming;
            if (!accepted) return;
            form.dataset.confirmed = 'true';
            form.requestSubmit(event.submitter || undefined);
        }, true);
        updateSelection();
    });
})();
