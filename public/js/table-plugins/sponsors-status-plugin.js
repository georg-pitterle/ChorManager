(function (window, document) {
    if (!window.ChorTableEngine || !window.ChorTableEngine.registerFilterPlugin) {
        return;
    }

    window.ChorTableEngine.registerFilterPlugin("sponsorStatus", function (context) {
        let state = { status: "" };
        let controls = null;

        function normalizeText(value) {
            return String(value || "").trim().toLowerCase();
        }

        function createSelect() {
            const wrapper = document.createElement("label");
            wrapper.className = "d-flex align-items-center gap-1 small";
            wrapper.textContent = "Status";

            const select = document.createElement("select");
            select.className = "form-select form-select-sm";
            select.setAttribute("aria-label", "Status filtern");

            [
                { value: "", label: "Alle" },
                { value: "prospect", label: "Interessent" },
                { value: "contacted", label: "Kontaktiert" },
                { value: "negotiating", label: "Verhandlung" },
                { value: "active", label: "Aktiv" },
                { value: "paused", label: "Pausiert" },
                { value: "closed", label: "Abgeschlossen" }
            ].forEach(function (entry) {
                const option = document.createElement("option");
                option.value = entry.value;
                option.textContent = entry.label;
                select.appendChild(option);
            });

            select.addEventListener("change", function () {
                state.status = normalizeText(select.value);
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

            if (state.status) {
                control.select.value = state.status;
            }
        }

        function getPredicate() {
            return function (row) {
                if (!state.status) {
                    return true;
                }

                const rowStatus = row && row.dataset ? normalizeText(row.dataset.status || row.dataset.sortStatus || "") : "";
                return rowStatus === state.status;
            };
        }

        return {
            mount: mount,
            getPredicate: getPredicate,
            getState: function () { return state; },
            setState: function (nextState) {
                state = Object.assign({ status: "" }, nextState || {});
            },
            reset: function () {
                state = { status: "" };
                if (controls) {
                    controls.reset();
                }
            }
        };
    });
})(window, document);
