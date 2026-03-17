(function() {
    document.addEventListener('DOMContentLoaded', function() {
        const table = document.getElementById('evaluationsTable');
        if (!table) return;

        const attrMap = ['sortName', 'sortVoice', 'sortPresent', 'sortExcused', 'sortUnexcused', 'sortPercentage'];
        const tbody = table.querySelector('tbody');
        const rows = Array.from(tbody.querySelectorAll('tr[data-sort-name]'));
        if (rows.length === 0) return;

        const headers = table.querySelectorAll('thead th.sortable');
        let currentSort = { col: -1, asc: true };

        function updateSortIcons() {
            headers.forEach((th, i) => {
                th.classList.remove('sorted-asc', 'sorted-desc');
                const icon = th.querySelector('.sort-icon');
                if (icon) {
                    icon.classList.remove('bi-caret-up', 'bi-caret-down', 'bi-arrow-down-up');
                    if (i === currentSort.col) {
                        th.classList.add(currentSort.asc ? 'sorted-asc' : 'sorted-desc');
                        icon.classList.add(currentSort.asc ? 'bi-caret-up' : 'bi-caret-down');
                    } else {
                        icon.classList.add('bi-arrow-down-up');
                    }
                }
            });
        }

        function doSort(col) {
            const type = headers[col].dataset.sort;
            const attr = attrMap[col];
            if (currentSort.col === col) currentSort.asc = !currentSort.asc;
            else { currentSort.col = col; currentSort.asc = true; }

            rows.sort((a, b) => {
                let va = a.dataset[attr] ?? '';
                let vb = b.dataset[attr] ?? '';
                if (type === 'number') {
                    va = parseFloat(va) || 0;
                    vb = parseFloat(vb) || 0;
                    return currentSort.asc ? va - vb : vb - va;
                }
                const cmp = String(va).localeCompare(String(vb), 'de');
                return currentSort.asc ? cmp : -cmp;
            });

            rows.forEach(r => tbody.appendChild(r));
            updateSortIcons();
        }

        headers.forEach((th, i) => {
            th.addEventListener('click', () => doSort(i));
            th.addEventListener('keydown', (e) => { 
                if (e.key === 'Enter' || e.key === ' ') { 
                    e.preventDefault(); 
                    doSort(i); 
                } 
            });
        });
    });
})();
