const normalize = value => value.toLocaleLowerCase('vi').normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/đ/g, 'd');

document.querySelectorAll('[data-table]').forEach(table => {
    const tbody = table.querySelector('tbody');
    if (!tbody) return;
    const rows = [...tbody.rows];
    const input = table.querySelector('[data-table-search]');
    const size = Math.max(1, Number(table.dataset.pageSize) || 10);
    const previous = table.querySelector('[data-table-prev]');
    const next = table.querySelector('[data-table-next]');
    const summary = table.querySelector('[data-table-summary]');
    let page = 0;
    let sortColumn = -1;
    let ascending = true;
    const collator = new Intl.Collator('vi', { numeric: true, sensitivity: 'base' });
    function render() {
        const filtered = rows.filter(row => normalize(row.textContent).includes(normalize(input.value).trim()));
        if (sortColumn >= 0) filtered.sort((a, b) => collator.compare(a.cells[sortColumn].textContent, b.cells[sortColumn].textContent) * (ascending ? 1 : -1));
        page = Math.max(0, Math.min(page, Math.ceil(filtered.length / size) - 1));
        rows.forEach(row => { row.hidden = true; });
        const displayed = filtered.slice(page * size, (page + 1) * size);
        displayed.forEach(row => { tbody.append(row); row.hidden = false; });
        const format = number => new Intl.NumberFormat('vi-VN').format(number);
        summary.textContent = filtered.length ? `${format(page * size + 1)}–${format(page * size + displayed.length)} / ${format(filtered.length)} kết quả` : '0 kết quả';
        previous.disabled = page === 0;
        next.disabled = (page + 1) * size >= filtered.length;
        table.querySelector('[data-table-no-results]').hidden = filtered.length !== 0;
    }
    input.addEventListener('input', () => { page = 0; render(); });
    previous.addEventListener('click', () => { page--; render(); });
    next.addEventListener('click', () => { page++; render(); });
    table.querySelectorAll('[data-table-sort]').forEach(button => button.addEventListener('click', () => {
        const column = Number(button.dataset.tableSort);
        ascending = column === sortColumn ? !ascending : true;
        sortColumn = column;
        table.querySelectorAll('th[aria-sort]').forEach(th => th.setAttribute('aria-sort', 'none'));
        button.closest('th').setAttribute('aria-sort', ascending ? 'ascending' : 'descending');
        page = 0;
        render();
    }));
    render();
});
