(function (window, document) {
    const AUTO_VIEW_HYSTERESIS_PX = 24;
    const DEFAULT_PAGE_SIZE = 100;
    const filterPluginFactories = {};

    function registerFilterPlugin(name, factory) {
        if (typeof name !== 'string' || !name.trim()) {
            return;
        }
        if (typeof factory !== 'function') {
            return;
        }
        filterPluginFactories[name.trim()] = factory;
    }

    window.ChorTableEngine = window.ChorTableEngine || {};
    window.ChorTableEngine.registerFilterPlugin = registerFilterPlugin;

    function asInt(value, fallback) {
        const parsed = Number.parseInt(value, 10);
        if (!Number.isFinite(parsed) || parsed <= 0) {
            return fallback;
        }
        return parsed;
    }

    function normalizeSortDir(value) {
        return value === 'desc' ? 'desc' : 'asc';
    }

    function normalizeMode(value) {
        if (value === 'auto' || value === 'cards' || value === 'table') {
            return value;
        }
        return 'auto';
    }

    function resolveAutoView(overflowDelta, currentView, useHysteresis) {
        if (currentView === 'cards') {
            if (useHysteresis) {
                return overflowDelta < -AUTO_VIEW_HYSTERESIS_PX ? 'table' : 'cards';
            }
            return overflowDelta > 1 ? 'cards' : 'table';
        }

        if (currentView === 'table') {
            return overflowDelta > 1 ? 'cards' : 'table';
        }

        return overflowDelta > 1 ? 'cards' : 'table';
    }

    window.ChorTableEngine.resolveAutoView = resolveAutoView;

    function createDefaultState(container) {
        const defaultPageSize = asInt(container.dataset.defaultPageSize, DEFAULT_PAGE_SIZE);
        const defaultSortKey = typeof container.dataset.defaultSortKey === 'string'
            ? container.dataset.defaultSortKey.trim()
            : '';
        const defaultSortDir = normalizeSortDir(container.dataset.defaultSortDir);
        return {
            page: 1,
            pageSize: defaultPageSize,
            searchQuery: '',
            sortKey: defaultSortKey,
            sortDir: defaultSortDir,
            pluginFilters: {}
        };
    }

    function getAllowedPageSizes(pageSizeSelect, defaultPageSize) {
        const allowed = [];

        if (pageSizeSelect && pageSizeSelect.options && pageSizeSelect.options.length > 0) {
            for (let i = 0; i < pageSizeSelect.options.length; i += 1) {
                const option = pageSizeSelect.options[i];
                const value = asInt(option && option.value, 0);
                if (value > 0 && !allowed.includes(value)) {
                    allowed.push(value);
                }
            }
        }

        if (defaultPageSize > 0 && !allowed.includes(defaultPageSize)) {
            allowed.push(defaultPageSize);
        }

        if (allowed.length === 0) {
            allowed.push(DEFAULT_PAGE_SIZE);
        }

        return allowed;
    }

    function normalizePageSize(value, allowedPageSizes, fallback) {
        const parsed = asInt(value, fallback);
        if (allowedPageSizes.includes(parsed)) {
            return parsed;
        }

        if (allowedPageSizes.includes(fallback)) {
            return fallback;
        }

        return allowedPageSizes[0] || fallback;
    }

    function createState(container, persistedState, allowedPageSizes) {
        const defaults = createDefaultState(container);
        const safe = persistedState && typeof persistedState === 'object' ? persistedState : {};
        const pluginFilters = safe.pluginFilters && typeof safe.pluginFilters === 'object' && !Array.isArray(safe.pluginFilters)
            ? safe.pluginFilters
            : {};

        return {
            page: asInt(safe.page, defaults.page),
            pageSize: normalizePageSize(safe.pageSize, allowedPageSizes, defaults.pageSize),
            searchQuery: typeof safe.searchQuery === 'string' ? safe.searchQuery : defaults.searchQuery,
            sortKey: typeof safe.sortKey === 'string' ? safe.sortKey : defaults.sortKey,
            sortDir: typeof safe.sortDir === 'string' ? normalizeSortDir(safe.sortDir) : defaults.sortDir,
            pluginFilters: pluginFilters
        };
    }

    function normalizeText(value) {
        return String(value || '').trim().toLowerCase();
    }

    function matchesSearch(row, query) {
        if (!query) {
            return true;
        }
        const text = normalizeText(row.textContent);
        return text.includes(query);
    }

    function matchesPluginFilters(row, pluginFilters) {
        const keys = Object.keys(pluginFilters || {});
        if (keys.length === 0) {
            return true;
        }

        const rowDataset = row && row.dataset ? row.dataset : {};
        return keys.every(function (key) {
            const rawValue = pluginFilters[key];
            if (rawValue && typeof rawValue === 'object') {
                return true;
            }

            const expected = normalizeText(rawValue);
            if (!expected) {
                return true;
            }
            return normalizeText(rowDataset[key]) === expected;
        });
    }

    function parsePluginNames(value) {
        if (typeof value !== 'string' || !value.trim()) {
            return [];
        }

        const seen = {};
        return value
            .split(',')
            .map(function (name) { return name.trim(); })
            .filter(function (name) {
                if (!name || seen[name]) {
                    return false;
                }
                seen[name] = true;
                return true;
            });
    }

    function getRowSortValue(row, sortKey) {
        if (!sortKey) {
            return normalizeText(row.textContent);
        }

        const dataKey = 'sort' + sortKey.charAt(0).toUpperCase() + sortKey.slice(1);
        if (row && row.dataset && Object.prototype.hasOwnProperty.call(row.dataset, dataKey)) {
            return normalizeText(row.dataset[dataKey]);
        }

        if (row && typeof row.querySelectorAll === 'function') {
            const cells = row.querySelectorAll('td');
            for (let i = 0; i < cells.length; i += 1) {
                const cell = cells[i];
                if (cell && cell.dataset && cell.dataset.sortKey === sortKey) {
                    if (cell.dataset.sortValue) {
                        return normalizeText(cell.dataset.sortValue);
                    }
                    return normalizeText(cell.textContent);
                }
            }
        }

        return normalizeText(row.textContent);
    }

    function sortRows(rows, sortKey, sortDir) {
        if (!sortKey) {
            return rows.slice();
        }

        const sorted = rows.slice().sort(function (a, b) {
            const aValue = getRowSortValue(a, sortKey);
            const bValue = getRowSortValue(b, sortKey);
            const numericA = Number(aValue);
            const numericB = Number(bValue);
            let result = 0;

            if (!Number.isNaN(numericA) && !Number.isNaN(numericB)) {
                result = numericA - numericB;
            } else {
                result = aValue.localeCompare(bValue, 'de', { numeric: true, sensitivity: 'base' });
            }

            if (result === 0) {
                return 0;
            }

            return sortDir === 'desc' ? -result : result;
        });

        return sorted;
    }

    function initTable(container) {
        const table = container.querySelector('table');
        if (!table) {
            return;
        }

        const tableId = container.dataset.tableId || table.id || 'table';
        let prefs = window.ChorTablePrefs ? window.ChorTablePrefs.read(tableId) : {};
        const modeButtons = container.querySelectorAll('[data-table-mode], [data-table-view]');
        const sortButtons = container.querySelectorAll('th[data-sort-key]');
        const rows = Array.from(table.querySelectorAll('tbody tr'));
        const searchInput = container.querySelector('[data-table-search]');
        const resetButton = container.querySelector('[data-table-reset]');
        const pageSizeSelect = container.querySelector('[data-table-page-size]');
        const pagePrevButton = container.querySelector('[data-table-page-prev]');
        const pageNextButton = container.querySelector('[data-table-page-next]');
        const pageLabel = container.querySelector('[data-table-page-label]');
        const pluginSlot = container.querySelector('[data-table-plugin-slot]');
        const defaults = createDefaultState(container);
        const allowedPageSizes = getAllowedPageSizes(pageSizeSelect, defaults.pageSize);
        const requestedPluginNames = parsePluginNames(container.dataset.tablePlugins);
        const plugins = [];
        let mode = normalizeMode(prefs.viewOverride || prefs.view || container.dataset.defaultView || 'auto');
        let state = createState(container, prefs.state, allowedPageSizes);
        let lastMeasuredTableWidth = 0;

        if (!table.id) {
            table.id = tableId;
        }

        function linkPaginationControl(control) {
            if (control && typeof control.setAttribute === 'function') {
                control.setAttribute('aria-controls', table.id);
            }
        }

        linkPaginationControl(pageSizeSelect);
        linkPaginationControl(pagePrevButton);
        linkPaginationControl(pageNextButton);

        function persistState() {
            if (!window.ChorTablePrefs) {
                return;
            }
            const nextPrefs = Object.assign({}, prefs, { state: state });
            window.ChorTablePrefs.write(tableId, nextPrefs);
            prefs = nextPrefs;
        }

        function matchCell(row, key, value) {
            const expected = normalizeText(value);
            if (!expected) {
                return true;
            }

            const rowDataset = row && row.dataset ? row.dataset : {};
            return normalizeText(rowDataset[key]) === expected;
        }

        function createSelectGroup(definitions) {
            const root = document.createElement('div');
            root.className = 'd-flex gap-2 align-items-center flex-wrap';
            const selects = {};
            let onChangeHandler = function () { };

            (definitions || []).forEach(function (definition) {
                if (!definition || typeof definition.key !== 'string') {
                    return;
                }

                const key = definition.key;
                const labelText = typeof definition.label === 'string' && definition.label.trim() ? definition.label : key;
                const values = [];
                const seen = {};

                rows.forEach(function (row) {
                    const rowValue = row && row.dataset ? normalizeText(row.dataset[key]) : '';
                    if (!rowValue || seen[rowValue]) {
                        return;
                    }
                    seen[rowValue] = true;
                    values.push(rowValue);
                });

                values.sort(function (a, b) {
                    return a.localeCompare(b, 'de', { sensitivity: 'base' });
                });

                const group = document.createElement('label');
                group.className = 'd-flex align-items-center gap-1 small';
                group.textContent = labelText;

                const select = document.createElement('select');
                select.className = 'form-select form-select-sm';
                select.setAttribute('aria-label', labelText + ' filtern');

                const allOption = document.createElement('option');
                allOption.value = '';
                allOption.textContent = 'Alle';
                select.appendChild(allOption);

                values.forEach(function (optionValue) {
                    const option = document.createElement('option');
                    option.value = optionValue;
                    option.textContent = optionValue;
                    select.appendChild(option);
                });

                select.addEventListener('change', function () {
                    onChangeHandler(readState());
                });

                selects[key] = select;
                group.appendChild(select);
                root.appendChild(group);
            });

            function readState() {
                const nextState = {};
                Object.keys(selects).forEach(function (key) {
                    nextState[key] = normalizeText(selects[key].value);
                });
                return nextState;
            }

            return {
                root: root,
                onChange: function (handler) {
                    onChangeHandler = typeof handler === 'function' ? handler : function () { };
                },
                reset: function () {
                    Object.keys(selects).forEach(function (key) {
                        selects[key].value = '';
                    });
                }
            };
        }

        function syncPluginStateIntoTableState() {
            const nextPluginFilters = {};
            plugins.forEach(function (entry) {
                if (typeof entry.plugin.getState === 'function') {
                    nextPluginFilters[entry.name] = entry.plugin.getState();
                }
            });
            state.pluginFilters = nextPluginFilters;
        }

        function onPluginStateChange() {
            state.page = 1;
            syncPluginStateIntoTableState();
            applyAndPersist();
        }

        function instantiatePlugins() {
            requestedPluginNames.forEach(function (name) {
                const factory = filterPluginFactories[name];
                if (typeof factory !== 'function') {
                    return;
                }

                const plugin = factory({
                    pluginSlot: pluginSlot,
                    onPluginStateChange: onPluginStateChange,
                    matchCell: matchCell,
                    createSelectGroup: createSelectGroup
                });

                if (!plugin || typeof plugin !== 'object') {
                    return;
                }

                if (typeof plugin.setState === 'function') {
                    const persistedPluginState = state.pluginFilters && typeof state.pluginFilters === 'object'
                        ? state.pluginFilters[name]
                        : null;
                    plugin.setState(persistedPluginState && typeof persistedPluginState === 'object' ? persistedPluginState : {});
                }

                if (pluginSlot && typeof plugin.mount === 'function') {
                    plugin.mount();
                }

                plugins.push({ name: name, plugin: plugin });
            });

            syncPluginStateIntoTableState();
        }

        function syncToolbarState(totalPages) {
            if (searchInput) {
                searchInput.value = state.searchQuery;
                searchInput.disabled = false;
            }

            if (resetButton) {
                resetButton.disabled = false;
            }

            if (pageSizeSelect) {
                pageSizeSelect.value = String(state.pageSize);
                pageSizeSelect.disabled = false;
            }

            if (pagePrevButton) {
                pagePrevButton.disabled = state.page <= 1;
            }

            if (pageNextButton) {
                pageNextButton.disabled = state.page >= totalPages;
            }

            if (pageLabel) {
                pageLabel.textContent = 'Seite ' + state.page + ' / ' + totalPages;
            }
        }

        function applyRows() {
            const pluginPredicates = plugins
                .map(function (entry) {
                    if (typeof entry.plugin.getPredicate !== 'function') {
                        return null;
                    }
                    const predicate = entry.plugin.getPredicate();
                    return typeof predicate === 'function' ? predicate : null;
                })
                .filter(function (predicate) { return predicate !== null; });

            const filteredRows = rows.filter(function (row) {
                return matchesSearch(row, state.searchQuery)
                    && matchesPluginFilters(row, state.pluginFilters)
                    && pluginPredicates.every(function (predicate) { return predicate(row); });
            });
            const sortedRows = sortRows(filteredRows, state.sortKey, state.sortDir);
            const totalPages = Math.max(1, Math.ceil(sortedRows.length / state.pageSize));
            state.page = Math.min(Math.max(1, state.page), totalPages);

            const start = (state.page - 1) * state.pageSize;
            const end = start + state.pageSize;
            const visibleRows = new Set(sortedRows.slice(start, end));

            rows.forEach(function (row) {
                row.hidden = !visibleRows.has(row);
            });

            syncToolbarState(totalPages);
        }

        function applyAndPersist() {
            applyRows();
            persistState();
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

            if (!viewportElement || !table) {
                return { availableWidth, requiredWidth: 0 };
            }

            const hadActiveView = Object.prototype.hasOwnProperty.call(container.dataset, 'activeView');
            const previousView = container.dataset.activeView;
            container.dataset.activeView = 'table';

            const requiredWidth = Math.max(
                viewportElement.scrollWidth || 0,
                table.scrollWidth || 0,
                table.clientWidth || 0
            );

            if (hadActiveView) {
                container.dataset.activeView = previousView;
            } else {
                delete container.dataset.activeView;
            }

            return { availableWidth, requiredWidth };
        }

        function getOverflowDelta(currentView) {
            const widths = measureWidths();
            const availableWidth = widths.availableWidth;
            const requiredWidth = widths.requiredWidth;

            if (availableWidth <= 0 || requiredWidth <= 0) {
                return 0;
            }

            // In cards view the table layout is transformed, so the max-content measurement
            // is unreliable. Keep using the last reliable measurement from when in table mode.
            if (currentView !== 'cards') {
                lastMeasuredTableWidth = requiredWidth;
            }

            if (currentView === 'cards' && lastMeasuredTableWidth > 0) {
                return lastMeasuredTableWidth - availableWidth;
            }

            return requiredWidth - availableWidth;
        }

        function getAutoView(currentView, useHysteresis) {
            const overflowDelta = getOverflowDelta(currentView);
            return resolveAutoView(overflowDelta, currentView, useHysteresis !== false);
        }

        function getEffectiveView(activeMode, currentView, options) {
            if (activeMode !== 'auto') {
                return activeMode;
            }

            return getAutoView(currentView, !(options && options.disableAutoHysteresis));
        }

        function applyEffectiveView(activeMode, options) {
            const currentView = container.dataset.activeView;
            container.dataset.activeView = getEffectiveView(activeMode, currentView, options);
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

        function applyMode(nextMode, shouldPersist, options) {
            mode = normalizeMode(nextMode);
            applyEffectiveView(mode, options);
            syncModeButtons(mode);
            if (shouldPersist) {
                persistMode(mode);
            }
        }

        modeButtons.forEach((btn) => {
            btn.addEventListener('click', function () {
                const clickedMode = btn.dataset.tableMode || btn.dataset.tableView;
                applyMode(
                    clickedMode,
                    true,
                    clickedMode === 'auto' ? { disableAutoHysteresis: true } : null
                );
            });
        });

        instantiatePlugins();

        applyRows();

        const initialWidths = measureWidths();
        if (initialWidths.requiredWidth > 0) {
            lastMeasuredTableWidth = initialWidths.requiredWidth;
        }

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

        if (searchInput) {
            searchInput.addEventListener('input', function () {
                state.searchQuery = normalizeText(searchInput.value);
                state.page = 1;
                applyAndPersist();
            });
        }

        sortButtons.forEach(function (header) {
            header.addEventListener('click', function () {
                const key = header.dataset.sortKey || '';
                if (!key) {
                    return;
                }

                if (state.sortKey === key) {
                    state.sortDir = state.sortDir === 'asc' ? 'desc' : 'asc';
                } else {
                    state.sortKey = key;
                    state.sortDir = 'asc';
                }

                state.page = 1;
                applyAndPersist();
            });
        });

        if (pageSizeSelect) {
            pageSizeSelect.addEventListener('change', function () {
                state.pageSize = normalizePageSize(pageSizeSelect.value, allowedPageSizes, defaults.pageSize);
                state.page = 1;
                applyAndPersist();
            });
        }

        if (pagePrevButton) {
            pagePrevButton.addEventListener('click', function () {
                if (state.page <= 1) {
                    return;
                }
                state.page -= 1;
                applyAndPersist();
            });
        }

        if (pageNextButton) {
            pageNextButton.addEventListener('click', function () {
                state.page += 1;
                applyAndPersist();
            });
        }

        if (resetButton) {
            resetButton.addEventListener('click', function () {
                state = createState(container, null, allowedPageSizes);
                plugins.forEach(function (entry) {
                    if (typeof entry.plugin.reset === 'function') {
                        entry.plugin.reset();
                    }
                    if (typeof entry.plugin.setState === 'function') {
                        const pluginState = state.pluginFilters && typeof state.pluginFilters === 'object'
                            ? state.pluginFilters[entry.name]
                            : null;
                        entry.plugin.setState(pluginState && typeof pluginState === 'object' ? pluginState : {});
                    }
                });
                syncPluginStateIntoTableState();

                if (window.ChorTablePrefs && typeof window.ChorTablePrefs.clear === 'function') {
                    window.ChorTablePrefs.clear(tableId);
                    prefs = {};
                } else {
                    prefs = {};
                    persistState();
                }

                mode = 'auto';
                applyMode(mode, false, { disableAutoHysteresis: true });
                applyRows();
            });
        }

    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-table-engine="true"]').forEach(initTable);
    });
})(window, document);
