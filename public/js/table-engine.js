(function (window, document) {
    function isMobileViewport() {
        return window.matchMedia('(max-width: 767.98px)').matches;
    }

    function initTable(container) {
        const table = container.querySelector('table');
        if (!table) {
            return;
        }

        const tableId = container.dataset.tableId || table.id || 'table';
        const prefs = window.ChorTablePrefs ? window.ChorTablePrefs.read(tableId) : {};

        const modeButtons = container.querySelectorAll('[data-table-view]');
        const configuredDefaultView = container.dataset.defaultView || 'table';
        const initialView = prefs.view || (isMobileViewport() ? configuredDefaultView : 'table');

        function setView(view) {
            container.dataset.activeView = view;
            if (window.ChorTablePrefs) {
                window.ChorTablePrefs.write(tableId, Object.assign({}, prefs, { view: view }));
            }
        }

        modeButtons.forEach((btn) => {
            btn.addEventListener('click', function () {
                setView(btn.dataset.tableView);
            });
        });

        setView(initialView);
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-table-engine="true"]').forEach(initTable);
    });
})(window, document);
