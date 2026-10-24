(function (window, document) {
    if (!window.ChorTableEngine || !window.ChorTableEngine.registerFilterPlugin) {
        return;
    }

    window.ChorTableEngine.registerFilterPlugin("sponsorCategory", function (context) {
        let state = { sponsorCategory: "" };
        let controls = null;

        function normalizeText(value) {
            return String(value || "").trim().toLowerCase();
        }

        // Die Kategorien stehen im Template am Tabellenrahmen, als Paare aus
        // Id und Name; jede Zeile trägt die Id ihrer Kategorie in data-category.
        // Gleicher Aufbau wie das Zustands-Plugin daneben.
        function readOptions() {
            const shell = context.root || document.querySelector("[data-category-options]");
            const raw = shell && shell.getAttribute ? shell.getAttribute("data-category-options") : null;

            if (!raw) {
                return [];
            }

            try {
                const parsed = JSON.parse(raw);
                return Array.isArray(parsed) ? parsed : [];
            } catch (error) {
                return [];
            }
        }

        function createSelect() {
            const wrapper = document.createElement("label");
            wrapper.className = "d-flex align-items-center gap-1 small";
            wrapper.textContent = "Kategorie";

            const select = document.createElement("select");
            select.className = "form-select form-select-sm";
            select.setAttribute("aria-label", "Kategorie filtern");

            const entries = [{ value: "", label: "Alle" }].concat(readOptions());
            entries.forEach(function (entry) {
                const option = document.createElement("option");
                option.value = entry.value;
                option.textContent = entry.label;
                select.appendChild(option);
            });

            select.addEventListener("change", function () {
                state.sponsorCategory = normalizeText(select.value);
                context.onPluginStateChange();
            });

            wrapper.appendChild(select);
            return { wrapper: wrapper, select: select };
        }

        function mount() {
            if (!context.pluginSlot) {
                return;
            }

            const control = createSelect();
            context.pluginSlot.appendChild(control.wrapper);

            controls = {
                select: control.select,
                reset: function () {
                    control.select.value = "";
                }
            };

            if (state.sponsorCategory) {
                control.select.value = state.sponsorCategory;
            }
        }

        function getPredicate() {
            return function (row) {
                if (!state.sponsorCategory) {
                    return true;
                }

                const rowCategory = row && row.dataset ? normalizeText(row.dataset.category || "") : "";
                return rowCategory === state.sponsorCategory;
            };
        }

        return {
            mount: mount,
            getPredicate: getPredicate,
            getState: function () { return state; },
            setState: function (nextState) {
                state = Object.assign({ sponsorCategory: "" }, nextState || {});
            },
            reset: function () {
                state = { sponsorCategory: "" };
                if (controls) {
                    controls.reset();
                }
            }
        };
    });
})(window, document);
