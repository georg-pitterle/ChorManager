(function (window, document) {
    if (!window.ChorTableEngine || !window.ChorTableEngine.registerFilterPlugin) {
        return;
    }

    window.ChorTableEngine.registerFilterPlugin("sponsorState", function (context) {
        let state = { sponsorState: "" };
        let controls = null;

        function normalizeText(value) {
            return String(value || "").trim().toLowerCase();
        }

        // Die Zustaende stehen im Template am Tabellenrahmen. Frueher pflegte
        // dieses Plugin eine eigene Kopie der Liste - sie lief mit jeder
        // Aenderung am Statusmodell auseinander.
        function readOptions() {
            const shell = context.root || document.querySelector("[data-state-options]");
            const raw = shell && shell.getAttribute ? shell.getAttribute("data-state-options") : null;

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
            wrapper.textContent = "Zustand";

            const select = document.createElement("select");
            select.className = "form-select form-select-sm";
            select.setAttribute("aria-label", "Zustand filtern");

            const entries = [{ value: "", label: "Alle" }].concat(readOptions());
            entries.forEach(function (entry) {
                const option = document.createElement("option");
                option.value = entry.value;
                option.textContent = entry.label;
                select.appendChild(option);
            });

            select.addEventListener("change", function () {
                state.sponsorState = normalizeText(select.value);
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

            if (state.sponsorState) {
                control.select.value = state.sponsorState;
            }
        }

        function getPredicate() {
            return function (row) {
                if (!state.sponsorState) {
                    return true;
                }

                const rowState = row && row.dataset ? normalizeText(row.dataset.state || row.dataset.sortState || "") : "";
                return rowState === state.sponsorState;
            };
        }

        return {
            mount: mount,
            getPredicate: getPredicate,
            getState: function () { return state; },
            setState: function (nextState) {
                state = Object.assign({ sponsorState: "" }, nextState || {});
            },
            reset: function () {
                state = { sponsorState: "" };
                if (controls) {
                    controls.reset();
                }
            }
        };
    });
})(window, document);
