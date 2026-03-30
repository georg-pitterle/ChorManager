(function (window, document) {
    const AUTO_VIEW_HYSTERESIS_PX = 24;

    function initTable(container) {
        const table = container.querySelector('table');
        if (!table) {
            return;
        }

        const tableId = container.dataset.tableId || table.id || 'table';
        let prefs = window.ChorTablePrefs ? window.ChorTablePrefs.read(tableId) : {};
        const modeButtons = container.querySelectorAll('[data-table-mode], [data-table-view]');
        let mode = prefs.viewOverride || prefs.view || 'auto';
        let lastMeasuredTableWidth = 0;

        function normalizeMode(value) {
            if (value === 'auto' || value === 'cards' || value === 'table') {
                return value;
            }
            return 'auto';
        }

        function getTableViewportElement() {
            if (typeof table.closest === 'function') {
                const responsiveWrapper = table.closest('.table-responsive');
                if (responsiveWrapper) {
                    return responsiveWrapper;
                }
            }
            return table.parentElement || container;
        }

        function measureWidths() {
            const viewportElement = getTableViewportElement();
            const availableWidth = viewportElement && viewportElement.clientWidth ? viewportElement.clientWidth : 0;
            const requiredWidth = table.scrollWidth || table.clientWidth || 0;

            return { availableWidth, requiredWidth };
        }

        function getOverflowDelta(currentView) {
            const widths = measureWidths();
            const availableWidth = widths.availableWidth;
            const requiredWidth = widths.requiredWidth;

            if (availableWidth <= 0 || requiredWidth <= 0) {
                return 0;
            }

            // In cards view the table layout is transformed, so scrollWidth is not a reliable
            // proxy for the native table width. Keep using the last reliable table-view measurement.
            if (currentView === 'table') {
                lastMeasuredTableWidth = requiredWidth;
            }

            if (currentView === 'cards' && lastMeasuredTableWidth > 0) {
                return lastMeasuredTableWidth - availableWidth;
            }

            return requiredWidth - availableWidth;
        }

        function getAutoView(currentView) {
            const overflowDelta = getOverflowDelta(currentView);

            if (currentView === 'cards') {
                return overflowDelta < -AUTO_VIEW_HYSTERESIS_PX ? 'table' : 'cards';
            }

            if (currentView === 'table') {
                return overflowDelta > 1 ? 'cards' : 'table';
            }

            return overflowDelta > 1 ? 'cards' : 'table';
        }

        function getEffectiveView(activeMode, currentView) {
            return activeMode === 'auto' ? getAutoView(currentView) : activeMode;
        }

        function applyEffectiveView(activeMode) {
            const currentView = container.dataset.activeView;
            container.dataset.activeView = getEffectiveView(activeMode, currentView);
        }

        function persistMode(nextMode) {
            if (!window.ChorTablePrefs) {
                return;
            }

            const nextPrefs = Object.assign({}, prefs);
            if (nextMode === 'auto') {
                delete nextPrefs.viewOverride;
                delete nextPrefs.view;
            } else {
                nextPrefs.viewOverride = nextMode;
                delete nextPrefs.view;
            }

            window.ChorTablePrefs.write(tableId, nextPrefs);
            prefs = nextPrefs;
        }

        function syncModeButtons(activeMode) {
            modeButtons.forEach((btn) => {
                const buttonMode = btn.dataset.tableMode || btn.dataset.tableView;
                const isActive = buttonMode === activeMode;
                btn.classList.toggle('active', isActive);
                btn.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            });
        }

        function applyMode(nextMode, shouldPersist) {
            mode = normalizeMode(nextMode);
            applyEffectiveView(mode);
            syncModeButtons(mode);
            if (shouldPersist) {
                persistMode(mode);
            }
        }

        modeButtons.forEach((btn) => {
            btn.addEventListener('click', function () {
                const clickedMode = btn.dataset.tableMode || btn.dataset.tableView;
                applyMode(clickedMode, true);
            });
        });

        applyMode(mode, false);

        window.addEventListener('resize', function () {
            if (mode === 'auto') {
                const nextView = getAutoView(container.dataset.activeView);
                if (container.dataset.activeView !== nextView) {
                    applyEffectiveView(mode);
                }
            }
        });

        if (typeof window.ResizeObserver === 'function') {
            const resizeObserver = new window.ResizeObserver(function () {
                if (mode === 'auto') {
                    const nextView = getAutoView(container.dataset.activeView);
                    if (container.dataset.activeView !== nextView) {
                        applyEffectiveView(mode);
                    }
                }
            });

            resizeObserver.observe(table);
            const viewportElement = getTableViewportElement();
            if (viewportElement) {
                resizeObserver.observe(viewportElement);
            }
        }

        const searchInput = container.querySelector('[data-table-search]');
        if (searchInput) {
            searchInput.addEventListener('input', function () {
                const q = searchInput.value.trim().toLowerCase();
                const rows = table.querySelectorAll('tbody tr');
                rows.forEach(function (row) {
                    if (!q) {
                        row.hidden = false;
                        return;
                    }
                    const text = row.textContent.toLowerCase();
                    row.hidden = !text.includes(q);
                });
            });
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-table-engine="true"]').forEach(initTable);
    });
})(window, document);
