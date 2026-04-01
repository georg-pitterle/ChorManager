(function (window, document) {
    if (!window.ChorTableEngine || !window.ChorTableEngine.registerFilterPlugin) {
        return;
    }

    window.ChorTableEngine.registerFilterPlugin('usersManage', function (context) {
        let state = { role: '', voice: '', project: '' };
        let controls = null;

        function mount() {
            controls = context.createSelectGroup([
                { key: 'role', label: 'Rolle' },
                { key: 'voice', label: 'Stimme' },
                { key: 'project', label: 'Projekt' }
            ]);
            context.pluginSlot.appendChild(controls.root);
            controls.onChange(function (nextState) {
                state = nextState;
                context.onPluginStateChange();
            });
        }

        function getPredicate() {
            return function (row) {
                return context.matchCell(row, 'role', state.role)
                    && context.matchCell(row, 'voice', state.voice)
                    && context.matchCell(row, 'project', state.project);
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