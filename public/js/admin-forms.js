(() => {
    let dialog;
    let resolveDialog;
    let previousFocus;

    function closeDialog(answer) {
        if (!resolveDialog) return;
        const resolve = resolveDialog;
        resolveDialog = null;
        dialog.close();
        previousFocus?.focus();
        resolve(answer);
    }

    // A wall of "field: old → new" sentences is the hardest thing to check in a confirmation, so a
    // caller can hand over grouped label and value rows instead and they print as an aligned list.
    function renderSections(container, sections) {
        container.replaceChildren();
        // A section with one group is already named by the dialog title, so an empty title prints
        // no heading rather than repeating itself.
        (sections || []).forEach(({ title, rows }) => {
            if (title) {
                const head = document.createElement('dt');
                head.className = 'col-span-2 mt-3 text-xs font-semibold uppercase tracking-wide text-gray-500 first:mt-0';
                head.textContent = title;
                container.append(head);
            }
            (rows || []).forEach(([label, value]) => {
                const name = document.createElement('dt');
                name.className = 'text-sm text-gray-500';
                name.textContent = label;
                const figure = document.createElement('dd');
                figure.className = 'text-sm tabular-nums text-gray-900';
                figure.textContent = value;
                container.append(name, figure);
            });
        });
        container.classList.toggle('hidden', !sections?.length);
    }

    // A form can hand over the field names it wants read back, so the details on the card are
    // the figures the person actually typed rather than a second copy assembled in the view.
    function confirmationSections(form) {
        let sections = [];
        if (form.dataset.confirmSections) {
            try { sections = JSON.parse(form.dataset.confirmSections) || []; } catch (error) { sections = []; }
        }
        if (!form.dataset.confirmRows) return sections;

        try {
            const rows = JSON.parse(form.dataset.confirmRows).map(([label, selector, source]) => {
                const field = form.querySelector(selector);
                if (!field) return null;
                let value = (field.value || '').trim();
                if (source === 'option') value = field.options?.[field.selectedIndex]?.textContent?.trim() || '';
                if (source === 'money') value = formatMoney(value, (form.querySelector('[name="currency"]')?.value || '').trim());
                return value ? [label, value] : null;
            }).filter(Boolean);
            if (rows.length) sections.push({ rows });
        } catch (error) {}

        return sections;
    }

    function formatMoney(value, currency) {
        const figure = Number(String(value).replace(/,/g, ''));
        if (!Number.isFinite(figure)) return value;
        const decimals = currency === 'UGX' ? 0 : 2;
        const amount = figure.toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
        if (currency === 'USD') return '$' + amount;
        if (currency === 'RMB') return '¥' + amount;
        return (currency ? currency + ' ' : '') + amount;
    }

    window.askConfirm = (options = {}) => {
        if (resolveDialog) return Promise.resolve(false);
        if (!dialog) {
            dialog = document.createElement('dialog');
            dialog.className = 'admin-confirm rounded-lg p-5 shadow-xl w-full max-w-md text-gray-900';
            dialog.setAttribute('aria-labelledby', 'admin-confirm-title');
            dialog.setAttribute('aria-describedby', 'admin-confirm-message');
            dialog.innerHTML = '<h2 id="admin-confirm-title" class="text-lg font-semibold"></h2>'
                + '<dl data-sections id="admin-confirm-sections" class="mt-1 grid grid-cols-[auto_1fr] items-baseline gap-x-3 gap-y-1"></dl>'
                + '<p id="admin-confirm-message" class="mt-3 whitespace-pre-line text-sm text-gray-700"></p>'
                + '<label data-typed-label class="hidden mt-4 text-sm">Type <strong data-expected></strong> to confirm'
                + '<input data-typed autocomplete="off" class="mt-2 w-full rounded-md border border-gray-300 px-3 py-2"></label>'
                + '<div class="mt-5 flex justify-end gap-2">'
                + '<button type="button" data-cancel class="rounded-md bg-gray-100 px-4 py-2 text-sm text-gray-800">Cancel</button>'
                + '<button type="button" data-confirm class="rounded-md bg-green-800 px-4 py-2 text-sm text-white disabled:opacity-50">Confirm</button></div>';
            document.body.appendChild(dialog);
            dialog.querySelector('[data-cancel]').addEventListener('click', () => closeDialog(false));
            dialog.querySelector('[data-confirm]').addEventListener('click', () => closeDialog(true));
            dialog.addEventListener('cancel', (event) => { event.preventDefault(); closeDialog(false); });
            dialog.addEventListener('click', (event) => {
                if (event.target !== dialog) return;
                const bounds = dialog.getBoundingClientRect();
                if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) closeDialog(false);
            });
            dialog.querySelector('[data-typed]').addEventListener('input', (event) => {
                dialog.querySelector('[data-confirm]').disabled = event.target.value !== dialog.dataset.expected;
            });
        }
        previousFocus = document.activeElement;
        const message = dialog.querySelector('#admin-confirm-message');
        const sections = dialog.querySelector('[data-sections]');
        dialog.querySelector('#admin-confirm-title').textContent = options.title || 'Confirm changes';
        message.textContent = options.message || '';
        message.classList.toggle('mt-3', !options.sections?.length);
        message.classList.toggle('mt-2', !!options.sections?.length);
        message.classList.toggle('text-red-700', !!options.danger);
        message.hidden = !options.message;
        renderSections(sections, options.sections);
        dialog.setAttribute('aria-describedby', options.sections?.length && options.message ? 'admin-confirm-sections admin-confirm-message' : options.sections?.length ? 'admin-confirm-sections' : 'admin-confirm-message');
        dialog.querySelector('[data-cancel]').textContent = options.cancelText || 'Cancel';
        const confirm = dialog.querySelector('[data-confirm]');
        confirm.textContent = options.confirmText || 'Confirm';
        confirm.classList.toggle('bg-green-800', !options.danger);
        confirm.classList.toggle('bg-red-700', !!options.danger);
        dialog.dataset.expected = options.typedValue || '';
        dialog.querySelector('[data-expected]').textContent = options.typedValue || '';
        dialog.querySelector('[data-typed-label]').classList.toggle('hidden', !options.typedValue);
        dialog.querySelector('[data-typed]').value = '';
        confirm.disabled = !!options.typedValue;
        return new Promise((resolve) => {
            resolveDialog = resolve;
            dialog.showModal();
            dialog.querySelector(options.typedValue ? '[data-typed]' : '[data-cancel]').focus();
        });
    };

    const messages = new WeakMap();
    const fields = (form) => Array.from(form.elements).filter((field) => field.matches('input,select,textarea') && field.name && !field.disabled && !['hidden', 'submit', 'button', 'reset'].includes(field.type));
    // The words a person sees beside the box, which is the only name they have for it. Tooltips
    // and the "(optional)" marker are decoration on the label, not part of the field's name.
    function labelOf(field) {
        if (!field) return '';
        const label = (field.id && field.form.querySelector(`label[for="${CSS.escape(field.id)}"]`)) || field.closest('label');
        return (label?.textContent || '').replace(/\(optional\)/i, '').replace(/[?]/g, '').replace(/\s+/g, ' ').trim();
    }
    function requiredMessage(field) {
        const label = labelOf(field);
        if (!label) return 'This field is required.';
        // A label that is already an instruction needs nothing added: "Confirm password." reads
        // better than "Enter confirm password."
        if (/^(choose|select|enter|confirm)\b/i.test(label)) return label.charAt(0).toUpperCase() + label.slice(1) + '.';
        // "Choose role." reads as an instruction, "Role is required." reads as a complaint.
        const noun = /^[A-Z]{2,}/.test(label) ? label : label.charAt(0).toLowerCase() + label.slice(1);
        const verb = field.tagName === 'SELECT' ? 'Choose'
            : (field.type === 'checkbox' || field.type === 'radio' ? 'Select' : 'Enter');
        return `${verb} ${noun}.`;
    }
    function focusField(field) {
        if (!field) return;
        // A searchable select is hidden behind the button the enhancer builds, so focus has to
        // land on that button or the jump goes nowhere.
        const target = field.tagName === 'SELECT' && !field.offsetParent
            ? field.parentElement?.querySelector('button[role=combobox]') || field
            : field;
        target.scrollIntoView({ block: 'center' });
        target.focus({ preventScroll: true });
    }
    function errorNode(field) {
        const name = field.name.replace(/\[\]$/, '');
        let node = Array.from(field.form.querySelectorAll('[data-field-error]')).find((el) => el.dataset.fieldError === name);
        if (!node) {
            node = document.createElement('p');
            node.dataset.fieldError = name;
            const wrapper = field.closest('[data-field]') || field.parentElement;
            wrapper.appendChild(node);
        }
        node.id ||= `${field.form.id || 'form'}-error-${name.replace(/[^\w-]/g, '-')}`;
        node.classList.add('text-sm', 'text-red-700', 'mt-1', 'field-error');
        node.setAttribute('aria-live', 'polite');
        return node;
    }
    function summary(form) {
        const active = Array.from(form.querySelectorAll('[data-field-error]')).filter((el) => el.textContent.trim() && !el.hidden);
        let banner = form.querySelector('[data-error-summary]');
        if (!banner) {
            banner = document.createElement('div');
            banner.dataset.errorSummary = '';
            banner.className = 'mb-4 min-h-[45px] rounded-md border border-red-200 bg-white p-3 text-sm text-red-800';
            banner.setAttribute('role', 'alert');
            banner.addEventListener('click', (event) => {
                const link = event.target.closest('a[data-jump-to]');
                if (!link) return;
                event.preventDefault();
                focusField(link.field);
            });
            form.prepend(banner);
        }
        // The count alone sends people hunting down a long form, so the banner names each field
        // and its link puts the cursor in that box.
        banner.textContent = '';
        if (active.length) {
            const lead = document.createElement('p');
            lead.textContent = `Please check the ${active.length} ${active.length === 1 ? 'field' : 'fields'} named here.`;
            banner.appendChild(lead);
            active.forEach((node) => {
                const link = document.createElement('a');
                link.href = `#${node.id}`;
                link.dataset.jumpTo = node.dataset.fieldError;
                link.field = node.field;
                link.textContent = labelOf(node.field) || node.dataset.fieldError;
                banner.appendChild(link);
            });
        }
        // The slot keeps its height when empty so a message cannot push the fields out from under a click.
        banner.hidden = false;
        banner.style.visibility = active.length ? 'visible' : 'hidden';
        return active.length;
    }
    function setError(field, message) {
        const node = errorNode(field);
        node.field = field;
        node.textContent = message;
        node.hidden = !message;
        field.setAttribute('aria-invalid', message ? 'true' : 'false');
        const describedBy = new Set((field.getAttribute('aria-describedby') || '').split(' ').filter(Boolean));
        if (message) describedBy.add(node.id); else describedBy.delete(node.id);
        if (describedBy.size) field.setAttribute('aria-describedby', Array.from(describedBy).join(' '));
        else field.removeAttribute('aria-describedby');
        field.classList.toggle('field-invalid', !!message);
        if (message) messages.set(field, { value: field.value, message });
        else messages.delete(field);
        summary(field.form);
    }
    function clearError(field) { setError(field, ''); }
    function validateField(field) {
        if (field.disabled || !field.willValidate) return true;
        const v = field.validity;
        let message = '';
        if (v.valueMissing) message = requiredMessage(field);
        else if (v.typeMismatch) message = field.type === 'email' ? 'Enter a valid email address.' : 'Enter a valid value.';
        else if (v.badInput) message = 'Enter a number.';
        else if (v.rangeUnderflow) message = `Enter ${field.min} or more.`;
        else if (v.rangeOverflow) message = `Enter ${field.max} or less.`;
        else if (v.tooShort || (field.minLength > 0 && field.value && field.value.length < field.minLength)) message = `Use at least ${field.minLength} characters.`;
        else if (v.tooLong) message = `Use no more than ${field.maxLength} characters.`;
        else if (v.patternMismatch) message = field.dataset.validationMessage || `${labelOf(field) || 'This field'} is not in the right format.`;
        if (!message && field.name === 'password_confirmation' && field.value !== field.form.elements.namedItem('password')?.value) message = 'Passwords do not match.';
        if (!message && messages.get(field)?.value === field.value) message = messages.get(field).message;
        setError(field, message);
        return !message;
    }
    function firstError(form) {
        focusField(fields(form).find((field) => field.getAttribute('aria-invalid') === 'true'));
    }
    function initialize(form) {
        if (form.dataset.validationReady) return;
        form.dataset.validationReady = '1';
        form.noValidate = true;
        fields(form).forEach((field) => {
            const node = errorNode(field);
            if (node.textContent.trim()) setError(field, node.textContent.trim());
            else node.hidden = true;
            field.addEventListener('input', () => {
                clearError(field);
                if (field.name === 'password') {
                    const confirmation = form.elements.namedItem('password_confirmation');
                    if (confirmation) clearError(confirmation);
                }
            });
            field.addEventListener('change', () => { clearError(field); validateField(field); });
            field.addEventListener('blur', () => validateField(field));
        });
        if (summary(form)) firstError(form);
    }
    window.formValidation = { setError, clearError, validateField, summary, initialize };

    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!form.matches('form[data-validate]')) return;
        initialize(form);
        const valid = fields(form).map(validateField).every(Boolean);
        if (!valid) {
            event.preventDefault();
            event.stopImmediatePropagation();
            firstError(form);
        }
    }, true);
    document.addEventListener('submit', (event) => {
        const form = event.target.closest('form[data-confirm]');
        if (!form || event.defaultPrevented) return;
        if (form.dataset.confirmed === '1') { delete form.dataset.confirmed; return; }
        event.preventDefault();
        const submitter = event.submitter;
        window.askConfirm({ title: form.dataset.confirmTitle, message: form.dataset.confirm, confirmText: form.dataset.confirmOk, danger: form.dataset.confirmDanger === '1', typedValue: form.dataset.confirmTyped, sections: confirmationSections(form) }).then((yes) => {
            if (!yes) return;
            form.dataset.confirmed = '1';
            form.requestSubmit(submitter);
        });
    });
    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!form.matches('form[data-validate]')) return;
        queueMicrotask(() => {
            if (event.defaultPrevented) return;
            form.setAttribute('aria-busy', 'true');
            form.querySelectorAll('button[type="submit"]').forEach((button) => {
                button.dataset.originalLabel = button.textContent;
                button.disabled = true;
                button.textContent = 'Saving…';
            });
        });
    });
    // Locking freezes the fields while the request is on its way, so a second click cannot send
    // the same money twice and nobody can retype a figure the books are already taking. The
    // fields stay enabled and keep their values; a disabled field is one the server never sees.
    function lockForm(form) {
        if (form.dataset.locked === '1') return;
        form.dataset.locked = '1';
        form.classList.add('form-locked');
        form.setAttribute('aria-busy', 'true');

        form.querySelectorAll('input, select, textarea, button, a[href]').forEach((field) => {
            field.dataset.lockedTabindex = field.getAttribute('tabindex') || '';
            field.setAttribute('tabindex', '-1');
        });
        form.querySelectorAll('input, select, textarea').forEach((field) => {
            if (['hidden', 'submit', 'button', 'reset'].includes(field.type)) return;
            if (field.matches('select, [type="checkbox"], [type="radio"], [type="file"]')) {
                field.setAttribute('aria-disabled', 'true');
                field.classList.add('field-locked');
            } else {
                field.readOnly = true;
            }
        });
        form.querySelectorAll('button[type="submit"]').forEach((button) => {
            button.dataset.lockText = button.textContent.trim();
            button.disabled = true;
            if (form.dataset.lockLabel) button.textContent = form.dataset.lockLabel;
        });
        if (form.contains(document.activeElement)) document.activeElement.blur();
    }

    function unlockForm(form) {
        form.querySelectorAll('[data-locked-tabindex]').forEach((field) => {
            const tabindex = field.dataset.lockedTabindex;
            if (tabindex) field.setAttribute('tabindex', tabindex);
            else field.removeAttribute('tabindex');
            delete field.dataset.lockedTabindex;
            field.removeAttribute('aria-disabled');
            field.classList.remove('field-locked');
            if (field.tagName === 'INPUT' || field.tagName === 'TEXTAREA') field.readOnly = false;
        });
        form.querySelectorAll('button[data-lock-text]').forEach((button) => {
            button.textContent = button.dataset.lockText;
            delete button.dataset.lockText;
            button.disabled = false;
        });
        form.classList.remove('form-locked');
        form.removeAttribute('aria-busy');
        delete form.dataset.locked;
    }

    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!form.matches('form[data-lock]')) return;
        // A confirmation card prevents the first submit, so the lock only falls once the person
        // has said yes and the request is actually leaving.
        queueMicrotask(() => {
            if (!event.defaultPrevented) lockForm(form);
        });
    });

    document.addEventListener('DOMContentLoaded', () => document.querySelectorAll('form[data-validate]').forEach(initialize));
    window.addEventListener('pageshow', () => {
        document.querySelectorAll('form[aria-busy="true"]').forEach((form) => {
            form.removeAttribute('aria-busy');
            form.querySelectorAll('button[data-original-label]').forEach((button) => {
                button.textContent = button.dataset.originalLabel;
                button.disabled = false;
            });
        });
        document.querySelectorAll('form[data-locked="1"]').forEach(unlockForm);
    });
})();
