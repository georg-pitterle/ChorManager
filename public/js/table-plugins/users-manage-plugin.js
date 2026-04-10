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

        function applyDedicatedFieldLayout(container, field) {
            if (!container || !field) {
                return;
            }

            container.className = 'col-12 col-md-3';
            field.className = 'd-flex flex-column gap-1 w-100';

            const select = field.querySelector('select');
            if (select) {
                select.className = 'form-select';
            }

            const textNode = Array.from(field.childNodes).find(function (node) {
                return node.nodeType === Node.TEXT_NODE && String(node.textContent || '').trim();
            });
            if (textNode) {
                const labelText = String(textNode.textContent || '').trim();
                textNode.textContent = '';

                const labelSpan = document.createElement('span');
                labelSpan.className = 'form-label small mb-0';
                labelSpan.textContent = labelText;
                field.insertBefore(labelSpan, field.firstChild);
            }
        }

        function createResetControl() {
            const column = document.createElement('div');
            column.className = 'col-12 col-md-3 d-flex align-items-end';

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-outline-secondary w-100';
            button.innerHTML = '<i class="bi bi-x-circle"></i> Reset';
            button.addEventListener('click', function () {
                state = { role: '', voice: '', project: '' };
                if (controls) {
                    controls.reset();
                }
                context.onPluginStateChange();
            });

            column.appendChild(button);
            return { column: column, button: button };
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
            const dedicatedSlot = tableContainer && typeof tableContainer.querySelector === 'function'
                ? tableContainer.parentElement.querySelector('[data-users-manage-filter-slot]')
                : null;
            const mountTarget = dedicatedSlot || context.pluginSlot;
            if (!mountTarget) {
                return;
            }

            const root = document.createElement('div');
            root.className = dedicatedSlot
                ? 'row g-3 align-items-end w-100 m-0'
                : 'd-flex gap-2 align-items-center flex-wrap';

            const roleControls = context.createSelectGroup([
                { key: 'role', label: 'Rolle' }
            ]);
            roleControls.onChange(function (nextState) {
                state.role = normalizeText(nextState.role);
                context.onPluginStateChange();
            });

            const voiceOptions = parseOptions(tableContainer && tableContainer.dataset ? tableContainer.dataset.voiceOptions : '');
            const projectOptions = parseOptions(tableContainer && tableContainer.dataset ? tableContainer.dataset.projectOptions : '');
            const voiceSelectControl = createSelect('Stimme', voiceOptions, 'voice');
            const projectSelectControl = createSelect('Projekt', projectOptions, 'project');
            const roleSelect = roleControls.root.querySelector('select');

            if (dedicatedSlot) {
                const voiceColumn = document.createElement('div');
                const projectColumn = document.createElement('div');
                const resetControl = createResetControl();

                applyDedicatedFieldLayout(roleControls.root, roleControls.root.firstElementChild);
                applyDedicatedFieldLayout(voiceColumn, voiceSelectControl.group);
                applyDedicatedFieldLayout(projectColumn, projectSelectControl.group);

                root.appendChild(roleControls.root);
                voiceColumn.appendChild(voiceSelectControl.group);
                projectColumn.appendChild(projectSelectControl.group);
                root.appendChild(voiceColumn);
                root.appendChild(projectColumn);
                root.appendChild(resetControl.column);
            } else {
                root.appendChild(roleControls.root);
                root.appendChild(voiceSelectControl.group);
                root.appendChild(projectSelectControl.group);
            }
            mountTarget.appendChild(root);

            controls = {
                root: root,
                role: roleControls,
                voice: voiceSelectControl.select,
                project: projectSelectControl.select,
                reset: function () {
                    roleControls.reset();
                    voiceSelectControl.select.value = '';
                    projectSelectControl.select.value = '';
                }
            };

            if (state.role && roleSelect) {
                roleSelect.value = state.role;
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
                return context.matchCell(row, 'role', state.role)
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