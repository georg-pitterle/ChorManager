(function (window) {
    const PREFIX = 'chor.table.';

    function key(tableId) {
        return PREFIX + tableId;
    }

    function read(tableId) {
        try {
            const raw = window.localStorage.getItem(key(tableId));
            return raw ? JSON.parse(raw) : {};
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

    window.ChorTablePrefs = { read, write };
})(window);
