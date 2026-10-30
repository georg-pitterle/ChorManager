(function (window, document) {
    if (!window.ChorTableEngine || !window.ChorTableEngine.registerFilterPlugin) {
        return;
    }

    window.ChorTableEngine.registerFilterPlugin("sponsorAssignee", function (context) {
        let state = { sponsorAssignee: "" };
        let controls = null;

        // Personen stehen im Template am Tabellenrahmen, als Paare aus Id und
        // Name, dazu "none" für Niemand. Ein Sponsor kann mehrere Zuständige
        // haben: data-assignees führt sie durch Leerzeichen getrennt.
        function readOptions() {
            const shell = context.root || document.querySelector("[data-assignee-options]");
            const raw = shell && shell.getAttribute ? shell.getAttribute("data-assignee-options") : null;

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
            wrapper.textContent = "Zuständig";

            const select = document.createElement("select");
            select.className = "form-select form-select-sm";
            select.setAttribute("aria-label", "Zuständigkeit filtern");

            const entries = [{ value: "", label: "Alle" }].concat(readOptions());
            entries.forEach(function (entry) {
                const option = document.createElement("option");
                option.value = entry.value;
                option.textContent = entry.label;
                select.appendChild(option);
            });

            select.addEventListener("change", function () {
                state.sponsorAssignee = String(select.value || "");
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

            if (state.sponsorAssignee) {
                control.select.value = state.sponsorAssignee;

                // Die Auswahl hängt vom Datenstand ab: Hat die gemerkte Person
                // keinen laufenden Vorgang mehr, gibt es ihre Option nicht, und
                // die Liste bliebe mit leerer Auswahl ohne Treffer.
                if (control.select.value !== state.sponsorAssignee) {
                    state.sponsorAssignee = "";
                }
            }
        }

        function getPredicate() {
            return function (row) {
                if (!state.sponsorAssignee) {
                    return true;
                }

                const tokens = row && row.dataset ? String(row.dataset.assignees || "").split(" ") : [];
                return tokens.indexOf(state.sponsorAssignee) !== -1;
            };
        }

        return {
            mount: mount,
            getPredicate: getPredicate,
            getState: function () { return state; },
            setState: function (nextState) {
                state = Object.assign({ sponsorAssignee: "" }, nextState || {});
            },
            reset: function () {
                state = { sponsorAssignee: "" };
                if (controls) {
                    controls.reset();
                }
            }
        };
    });
})(window, document);
