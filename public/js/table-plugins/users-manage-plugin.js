(function (window, document) {
    if (!window.ChorTableEngine || !window.ChorTableEngine.registerFilterPlugin) {
        return;
    }

    window.ChorTableEngine.registerFilterPlugin('usersManage', function (context) {
        let state = { role: '', voice: '', project: '' };
        let controls = null;

        function normalizeText(value) {
            return String(value || '').trim().toLowerCase();
        }

        function parseOptions(rawValue) {
            const seen = {};
            if (typeof rawValue !== 'string' || !rawValue.trim()) {
                return [];
            }

            return rawValue
                .split('||')
                .map(function (entry) { return entry.trim(); })
                .filter(function (entry) { return entry.length > 0; })
                .map(function (entry) {
                    const separatorIndex = entry.indexOf('::');
                    if (separatorIndex === -1) {
                        return null;
                    }
                    const id = normalizeText(entry.slice(0, separatorIndex));
                    const label = String(entry.slice(separatorIndex + 2) || '').trim();
                    if (!id || !label || seen[id]) {
                        return null;
                    }
                    seen[id] = true;
                    return { value: id, label: label };
                })
                .filter(function (entry) { return entry !== null; })
                .sort(function (a, b) {
                    return a.label.localeCompare(b.label, 'de', { sensitivity: 'base' });
                });
        }

        function createSelect(labelText, options, key) {
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

            (options || []).forEach(function (optionEntry) {
                const option = document.createElement('option');
                option.value = optionEntry.value;
                option.textContent = optionEntry.label;
                select.appendChild(option);
            });

            select.addEventListener('change', function () {
                state[key] = normalizeText(select.value);
                context.onPluginStateChange();
            });

            group.appendChild(select);
            return { group: group, select: select };
        }

        function createResetControl() {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-sm btn-outline-secondary';
            button.innerHTML = '<i class="bi bi-x-circle"></i> Reset';
            button.addEventListener('click', function () {
                state = { role: '', voice: '', project: '' };
                if (controls) {
                    controls.reset();
                }
                context.onPluginStateChange();
            });
            return { button: button };
        }

        function rowHasToken(row, key, token) {
            const expected = normalizeText(token);
            if (!expected) {
                return true;
            }

            const rowDataset = row && row.dataset ? row.dataset : {};
            const rowValue = String(rowDataset[key] || '');
            return rowValue.indexOf('|' + expected + '|') !== -1;
        }

        function mount() {
            const tableContainer = context.pluginSlot.closest('[data-table-engine="true"]');
            if (!context.pluginSlot || !tableContainer) {
                return;
            }

            const root = document.createElement('div');
            root.className = 'd-flex gap-2 align-items-center flex-wrap';

            const roleOptions = parseOptions(tableContainer && tableContainer.dataset ? tableContainer.dataset.roleOptions : '');
            const voiceOptions = parseOptions(tableContainer && tableContainer.dataset ? tableContainer.dataset.voiceOptions : '');
            const projectOptions = parseOptions(tableContainer && tableContainer.dataset ? tableContainer.dataset.projectOptions : '');
            const roleSelectControl = createSelect('Rolle', roleOptions, 'role');
            const voiceSelectControl = createSelect('Stimme', voiceOptions, 'voice');
            const projectSelectControl = createSelect('Projekt', projectOptions, 'project');

            const resetControl = createResetControl();
            root.appendChild(roleSelectControl.group);
            root.appendChild(voiceSelectControl.group);
            root.appendChild(projectSelectControl.group);
            root.appendChild(resetControl.button);
            context.pluginSlot.appendChild(root);

            controls = {
                root: root,
                role: roleSelectControl.select,
                voice: voiceSelectControl.select,
                project: projectSelectControl.select,
                reset: function () {
                    roleSelectControl.select.value = '';
                    voiceSelectControl.select.value = '';
                    projectSelectControl.select.value = '';
                }
            };

            if (state.role) {
                roleSelectControl.select.value = state.role;
            }
            if (state.voice) {
                voiceSelectControl.select.value = state.voice;
            }
            if (state.project) {
                projectSelectControl.select.value = state.project;
            }
        }

        function getPredicate() {
            return function (row) {
                return rowHasToken(row, 'role', state.role)
                    && rowHasToken(row, 'voice', state.voice)
                    && rowHasToken(row, 'project', state.project);
            };
        }

        return {
            mount: mount,
            getPredicate: getPredicate,
            getState: function () { return state; },
            setState: function (nextState) {
                state = Object.assign({ role: '', voice: '', project: '' }, nextState || {});
            },
            reset: function () {
                state = { role: '', voice: '', project: '' };
                if (controls) {
                    controls.reset();
                }
            }
        };
    });
})(window, document);
