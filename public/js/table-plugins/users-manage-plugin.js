(function (window, document) {
    if (!window.ChorTableEngine || !window.ChorTableEngine.registerFilterPlugin) {
        return;
    }

    window.ChorTableEngine.registerFilterPlugin('usersManage', function (context) {
        const emptyState = { role: '', voice: '', project: '', invitation: '' };
        // Feste Auswahl statt Optionsliste aus dem Template: Es gibt nur diese
        // zwei Lagen, und die Werte stehen so auch im data-invitation der Zeilen.
        const invitationOptions = [
            { value: 'sent', label: 'gesendet' },
            { value: 'not_sent', label: 'nicht gesendet' },
            { value: 'pending', label: 'offen' }
        ];
        let state = Object.assign({}, emptyState);
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
                // Reihenfolge wie im Template: Stimmgruppen Sopran, Alt, Tenor, Bass,
                // Rollen nach Stufe, Projekte nach Saison. Alphabetisch sortiert
                // stand Alt vor Sopran, und die Liste wirkte wie ein fremdes Programm.
                .filter(function (entry) { return entry !== null; });
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
            // Im Archiv kein Projekt-Filter: Archivierte sind in keinem laufenden
            // Projekt, die Auswahl führte nur ins Leere. Ein aus der aktiven Liste
            // gemerkter Projektfilter darf dort auch nicht unsichtbar weiterwirken.
            const isArchive = tableContainer.dataset.showArchived === '1';
            const projectSelectControl = isArchive ? null : createSelect('Projekt', projectOptions, 'project');
            if (isArchive) {
                state.project = '';
            }

            root.appendChild(roleSelectControl.group);
            root.appendChild(voiceSelectControl.group);
            if (projectSelectControl) {
                root.appendChild(projectSelectControl.group);
            }

            // Nur für die Mitgliederverwaltung - dieselbe Bedingung wie der
            // Abschnitt "Kontoaktivität" im Bearbeiten-Dialog.
            const invitationSelectControl = tableContainer.dataset.invitationFilter === '1'
                ? createSelect('Einladung', invitationOptions, 'invitation')
                : null;
            if (invitationSelectControl) {
                root.appendChild(invitationSelectControl.group);
            }
            context.pluginSlot.appendChild(root);

            controls = {
                root: root,
                role: roleSelectControl.select,
                voice: voiceSelectControl.select,
                project: projectSelectControl ? projectSelectControl.select : null,
                reset: function () {
                    roleSelectControl.select.value = '';
                    voiceSelectControl.select.value = '';
                    if (projectSelectControl) {
                        projectSelectControl.select.value = '';
                    }
                    if (invitationSelectControl) {
                        invitationSelectControl.select.value = '';
                    }
                }
            };

            if (invitationSelectControl && state.invitation) {
                invitationSelectControl.select.value = state.invitation;
            }

            if (state.role) {
                roleSelectControl.select.value = state.role;
            }
            if (state.voice) {
                voiceSelectControl.select.value = state.voice;
            }
            if (state.project && projectSelectControl) {
                projectSelectControl.select.value = state.project;
            }
        }

        function getPredicate() {
            return function (row) {
                return rowHasToken(row, 'role', state.role)
                    && rowHasToken(row, 'voice', state.voice)
                    && rowHasToken(row, 'project', state.project)
                    && rowHasToken(row, 'invitation', state.invitation);
            };
        }

        return {
            mount: mount,
            getPredicate: getPredicate,
            getState: function () { return state; },
            setState: function (nextState) {
                state = Object.assign({}, emptyState, nextState || {});
            },
            reset: function () {
                state = Object.assign({}, emptyState);
                if (controls) {
                    controls.reset();
                }
            }
        };
    });
})(window, document);
