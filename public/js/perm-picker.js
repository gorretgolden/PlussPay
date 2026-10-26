(() => {
    const PAGE_SIZE = 4;
    document.querySelectorAll('.perm-picker').forEach((picker) => {
        if (picker.dataset.pagerReady) return;
        picker.dataset.pagerReady = '1';
        const groups = [...picker.querySelectorAll('.perm-group')];
        const filter = picker.querySelector('.perm-filter');
        if (!groups.length) return;
        const pageCount = Math.ceil(groups.length / PAGE_SIZE);
        let page = 0;

        const bar = document.createElement('div');
        bar.className = 'perm-pager flex flex-wrap items-center gap-3 text-sm';
        const label = document.createElement('span');
        label.className = 'text-gray-500';
        const prev = document.createElement('button');
        prev.type = 'button';
        prev.textContent = 'Previous';
        prev.className = 'min-h-[44px] px-3 text-green-800 font-medium disabled:opacity-40 disabled:text-gray-400';
        const next = document.createElement('button');
        next.type = 'button';
        next.textContent = 'Next';
        next.className = 'min-h-[44px] px-3 text-green-800 font-medium disabled:opacity-40 disabled:text-gray-400';
        bar.append(prev, label, next);

        // A search spans every page, so the pager steps aside while one is active.
        const apply = () => {
            const term = (filter?.value || '').trim().toLowerCase();
            const searching = term.length > 0;
            picker.querySelectorAll('.perm-item').forEach((item) => {
                item.classList.toggle('hidden', !item.dataset.name.toLowerCase().includes(term));
            });
            groups.forEach((group, index) => {
                const matched = [...group.querySelectorAll('.perm-item')].some((item) => !item.classList.contains('hidden'));
                const onPage = index >= page * PAGE_SIZE && index < (page + 1) * PAGE_SIZE;
                group.classList.toggle('hidden', searching ? !matched : !onPage);
                if (searching) group.open = true;
            });
            bar.hidden = searching;
            if (!searching) {
                label.textContent = `Groups ${page * PAGE_SIZE + 1}-${Math.min((page + 1) * PAGE_SIZE, groups.length)} of ${groups.length}`;
                prev.disabled = page === 0;
                next.disabled = page >= pageCount - 1;
            }
            const noMatch = picker.querySelector('.perm-no-match');
            if (noMatch) noMatch.classList.toggle('hidden', groups.some((group) => !group.classList.contains('hidden')));
        };
        prev.addEventListener('click', () => { page = Math.max(0, page - 1); apply(); });
        next.addEventListener('click', () => { page = Math.min(pageCount - 1, page + 1); apply(); });
        filter?.addEventListener('input', () => { page = 0; apply(); });
        // The box filters as the person types, so Enter has no job here. Without this guard an
        // Enter can fire the surrounding form's save flow while a search is sitting in the box.
        filter?.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') event.preventDefault();
        });

        groups[0].before(bar);
        apply();
    });
})();
