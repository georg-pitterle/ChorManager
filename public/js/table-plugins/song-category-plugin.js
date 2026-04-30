(function (window, document) {
    if (!window.ChorTableEngine || !window.ChorTableEngine.registerFilterPlugin) {
        return;
    }

    window.ChorTableEngine.registerFilterPlugin("songCategory", function (context) {
        let state = { category: "" };
        let controls = null;

        function normalizeText(value) {
            return String(value || "").trim().toLowerCase();
        }

        function parseOptions(rawValue) {
            const seen = {};
            if (typeof rawValue !== "string" || !rawValue.trim()) {
                return [];
            }

            return rawValue
                .split("||")
                .map(function (entry) { return entry.trim(); })
                .filter(function (entry) { return entry.length > 0; })
                .map(function (entry) {
                    const separatorIndex = entry.indexOf("::");
                    if (separatorIndex === -1) {
                        return null;
                    }

                    const id = normalizeText(entry.slice(0, separatorIndex));
                    const label = String(entry.slice(separatorIndex + 2) || "").trim();
                    if (!id || !label || seen[id]) {
                        return null;
                    }

                    seen[id] = true;
                    return { value: id, label: label };
                })
                .filter(function (entry) { return entry !== null; })
                .sort(function (a, b) {
                    return a.label.localeCompare(b.label, "de", { sensitivity: "base" });
                });
        }

        function createSelect(options) {
            const wrapper = document.createElement("label");
            wrapper.className = "d-flex align-items-center gap-1 small";
            wrapper.textContent = "Kategorie";

            const select = document.createElement("select");
            select.className = "form-select form-select-sm";
            select.setAttribute("aria-label", "Kategorie filtern");

            const allOption = document.createElement("option");
            allOption.value = "";
            allOption.textContent = "Alle";
            select.appendChild(allOption);

            options.forEach(function (optionEntry) {
                const option = document.createElement("option");
                option.value = optionEntry.value;
                option.textContent = optionEntry.label;
                select.appendChild(option);
            });

            select.addEventListener("change", function () {
                state.category = normalizeText(select.value);
                context.onPluginStateChange();
            });

            wrapper.appendChild(select);
            return { wrapper: wrapper, select: select };
        }

        function mount() {
            if (!context.pluginSlot) {
                return;
            }

            const tableContainer = context.pluginSlot.closest('[data-table-engine="true"]');
            const options = parseOptions(tableContainer && tableContainer.dataset ? tableContainer.dataset.categoryOptions : "");
            const selectControl = createSelect(options);

            context.pluginSlot.appendChild(selectControl.wrapper);

            controls = {
                select: selectControl.select,
                reset: function () {
                    selectControl.select.value = "";
                }
            };

            if (state.category) {
                selectControl.select.value = state.category;
            }
        }

        function rowHasToken(row, key, token) {
            const expected = normalizeText(token);
            if (!expected) {
                return true;
            }

            const rowDataset = row && row.dataset ? row.dataset : {};
            const rowValue = String(rowDataset[key] || "");
            return rowValue.indexOf("|" + expected + "|") !== -1;
        }

        function getPredicate() {
            return function (row) {
                return rowHasToken(row, "category", state.category);
            };
        }

        return {
            mount: mount,
            getPredicate: getPredicate,
            getState: function () { return state; },
            setState: function (nextState) {
                state = Object.assign({ category: "" }, nextState || {});
            },
            reset: function () {
                state = { category: "" };
                if (controls) {
                    controls.reset();
                }
            }
        };
    });
})(window, document);
