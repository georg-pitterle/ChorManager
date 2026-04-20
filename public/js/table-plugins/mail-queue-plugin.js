(function (window, document) {
    if (!window.ChorTableEngine || !window.ChorTableEngine.registerFilterPlugin) {
        return;
    }

    window.ChorTableEngine.registerFilterPlugin('mailQueueFilters', function (context) {
        var state = { status: '', type: '' };
        var controls = null;

        function normalizeText(value) {
            return String(value || '').trim().toLowerCase();
        }

        function createSelect(labelText, options, key) {
            var group = document.createElement('label');
            group.className = 'd-flex align-items-center gap-1 small';
            group.textContent = labelText;

            var select = document.createElement('select');
            select.className = 'form-select form-select-sm';
            select.setAttribute('aria-label', labelText + ' filtern');

            var allOption = document.createElement('option');
            allOption.value = '';
            allOption.textContent = 'Alle';
            select.appendChild(allOption);

            (options || []).forEach(function (optionEntry) {
                var option = document.createElement('option');
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

        function mount() {
            if (!context.pluginSlot) {
                return;
            }

            var root = document.createElement('div');
            root.className = 'd-flex gap-2 align-items-center flex-wrap';

            var statusControl = createSelect('Status', [
                { value: 'queued', label: 'In Warteschlange' },
                { value: 'sending', label: 'Wird gesendet' },
                { value: 'sent', label: 'Versendet' },
                { value: 'skipped', label: 'Uebersprungen' },
                { value: 'failed', label: 'Fehlgeschlagen' },
                { value: 'dead', label: 'Endgueltig fehlgeschlagen' }
            ], 'status');
            var typeControl = createSelect('Typ', [
                { value: 'newsletter', label: 'Newsletter' },
                { value: 'invitation', label: 'Einladung' },
                { value: 'password_reset', label: 'Passwort zurücksetzen' }
            ], 'type');

            root.appendChild(statusControl.group);
            root.appendChild(typeControl.group);
            context.pluginSlot.appendChild(root);

            controls = {
                status: statusControl.select,
                type: typeControl.select,
                reset: function () {
                    statusControl.select.value = '';
                    typeControl.select.value = '';
                }
            };

            if (state.status) {
                statusControl.select.value = state.status;
            }
            if (state.type) {
                typeControl.select.value = state.type;
            }
        }

        function getPredicate() {
            return function (row) {
                var rowDataset = row && row.dataset ? row.dataset : {};
                var matchesStatus = !state.status || normalizeText(rowDataset.queueStatus) === state.status;
                var matchesType = !state.type || normalizeText(rowDataset.queueType) === state.type;
                return matchesStatus && matchesType;
            };
        }

        return {
            mount: mount,
            getPredicate: getPredicate,
            getState: function () { return state; },
            setState: function (nextState) {
                state = Object.assign({ status: '', type: '' }, nextState || {});
                if (controls) {
                    controls.status.value = state.status || '';
                    controls.type.value = state.type || '';
                }
            },
            reset: function () {
                state = { status: '', type: '' };
                if (controls) {
                    controls.reset();
                }
            }
        };
    });
})(window, document);
