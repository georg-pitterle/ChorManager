(function (window) {
    const PREFIX = 'chor.table.';

    function key(tableId) {
        return PREFIX + tableId;
    }

    function read(tableId) {
        try {
            const raw = window.localStorage.getItem(key(tableId));
            const parsed = raw ? JSON.parse(raw) : {};
            if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
                return parsed;
            }
            return {};
        } catch (_e) {
            return {};
        }
    }

    function write(tableId, value) {
        try {
            window.localStorage.setItem(key(tableId), JSON.stringify(value));
        } catch (_e) {
            // Intentionally noop fallback.
        }
    }

    function clear(tableId) {
        try {
            window.localStorage.removeItem(key(tableId));
        } catch (_e) {
            // Intentionally noop fallback.
        }
    }

    window.ChorTablePrefs = { read, write, clear };
})(window);
