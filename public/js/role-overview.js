/**
 * Rollenübersicht unter /roles: Rolle wählen, Rechte durchsuchen und zu einem Recht
 * anzeigen, welche Rollen es haben. Die reine Logik oben ist ohne DOM unter
 * `node --test` prüfbar; der DOM-Teil läuft nur im Browser.
 */
(function (global) {
    'use strict';

    var STORAGE_KEY = 'roles.selectedRoleId';
    var AUTO_EXPAND_LIMIT = 3;

    function normalizeQuery(value) {
        return String(value || '')
            .trim()
            .toLowerCase()
            .normalize('NFD')
            .replace(/[̀-ͯ]/g, '');
    }

    function matchPermissions(permissions, query) {
        var needle = normalizeQuery(query);
        return permissions
            .filter(function (permission) {
                return needle === '' || normalizeQuery(permission.label).indexOf(needle) !== -1;
            })
            .map(function (permission) {
                return permission.key;
            });
    }

    function autoExpandKeys(matches, query) {
        if (normalizeQuery(query) === '' || matches.length === 0 || matches.length > AUTO_EXPAND_LIMIT) {
            return [];
        }
        return matches.slice();
    }

    function resolveInitialRoleId(roleIds, hash, storedId) {
        var match = /^#role-(\d+)$/.exec(hash || '');
        if (match && roleIds.indexOf(match[1]) !== -1) {
            return match[1];
        }
        if (storedId && roleIds.indexOf(storedId) !== -1) {
            return storedId;
        }
        return roleIds.length > 0 ? roleIds[0] : null;
    }

    function readStoredRoleId() {
        try {
            return global.sessionStorage.getItem(STORAGE_KEY);
        } catch (error) {
            return null;
        }
    }

    function storeRoleId(roleId) {
        try {
            global.sessionStorage.setItem(STORAGE_KEY, roleId);
        } catch (error) {
            // Ohne Speicher startet die Seite beim nächsten Laden mit der ersten Rolle.
        }
    }

    function initRoleOverview(root) {
        var select = root.querySelector('[data-role-select]');
        var roleButtons = Array.prototype.slice.call(root.querySelectorAll('[data-role-id]'));
        var details = Array.prototype.slice.call(root.querySelectorAll('[data-role-detail]'));
        var search = root.querySelector('[data-permission-search]');
        var empty = root.querySelector('[data-permission-empty]');
        var who = root.querySelector('[data-role-who]');
        var whoLabel = root.querySelector('[data-role-who-label]');
        var source = root.querySelector('[data-permission-holders-source]');
        var roleIds = details.map(function (detail) {
            return detail.getAttribute('data-role-detail');
        });
        var permissions = [];
        var state = { roleId: null, activeKey: null, expandedKeys: [] };

        if (details.length > 0) {
            Array.prototype.forEach.call(details[0].querySelectorAll('[data-permission-key]'), function (item) {
                permissions.push({
                    key: item.getAttribute('data-permission-key'),
                    label: item.getAttribute('data-permission-label'),
                });
            });
        }

        function holdersFor(key) {
            return source.querySelector('[data-permission-holders="' + key + '"]');
        }

        function labelFor(key) {
            for (var i = 0; i < permissions.length; i++) {
                if (permissions[i].key === key) {
                    return permissions[i].label;
                }
            }
            return '';
        }

        function renderHolders(detail) {
            Array.prototype.forEach.call(detail.querySelectorAll('[data-permission-key]'), function (item) {
                var key = item.getAttribute('data-permission-key');
                var open = key === state.activeKey || state.expandedKeys.indexOf(key) !== -1;
                var toggle = item.querySelector('[data-permission-toggle]');
                var slot = item.querySelector('[data-permission-holders-slot]');
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                slot.replaceChildren();
                slot.hidden = !open;
                if (!open) {
                    return;
                }
                // Kopie statt HTML-String: Rollennamen bleiben so garantiert Text.
                var copy = holdersFor(key).cloneNode(true);
                Array.prototype.forEach.call(copy.querySelectorAll('[data-role-jump]'), function (chip) {
                    chip.classList.toggle('is-current', chip.getAttribute('data-role-jump') === state.roleId);
                });
                while (copy.firstChild) {
                    slot.appendChild(copy.firstChild);
                }
            });
        }

        function renderRoleMarkers() {
            var holderIds = state.activeKey ? holdersFor(state.activeKey).getAttribute('data-role-ids').split(',') : null;
            who.hidden = holderIds === null;
            whoLabel.textContent = holderIds === null ? '' : labelFor(state.activeKey);
            roleButtons.forEach(function (button) {
                var id = button.getAttribute('data-role-id');
                var marker = button.querySelector('[data-role-marker]');
                var has = holderIds !== null && holderIds.indexOf(id) !== -1;
                button.setAttribute('aria-pressed', id === state.roleId ? 'true' : 'false');
                button.classList.toggle('is-lacking', holderIds !== null && !has);
                marker.className = 'role-overview-role-marker' + (has ? ' bi bi-check-circle-fill text-success' : '');
            });
        }

        function render() {
            details.forEach(function (detail) {
                var visible = detail.getAttribute('data-role-detail') === state.roleId;
                detail.hidden = !visible;
                if (visible) {
                    renderHolders(detail);
                }
            });
            select.value = state.roleId;
            renderRoleMarkers();
        }

        function selectRole(roleId) {
            if (roleIds.indexOf(roleId) === -1) {
                return;
            }
            state.roleId = roleId;
            storeRoleId(roleId);
            if (global.history && global.history.replaceState) {
                global.history.replaceState(null, '', '#role-' + roleId);
            }
            render();
        }

        function focusPermission(key) {
            details.forEach(function (detail) {
                if (detail.getAttribute('data-role-detail') !== state.roleId) {
                    return;
                }
                var toggle = detail.querySelector('[data-permission-key="' + key + '"] [data-permission-toggle]');
                if (toggle) {
                    toggle.focus();
                }
            });
        }

        function applySearch() {
            var matches = matchPermissions(permissions, search.value);
            details.forEach(function (detail) {
                Array.prototype.forEach.call(detail.querySelectorAll('[data-permission-group]'), function (group) {
                    var anyVisible = false;
                    Array.prototype.forEach.call(group.querySelectorAll('[data-permission-key]'), function (item) {
                        var visible = matches.indexOf(item.getAttribute('data-permission-key')) !== -1;
                        item.hidden = !visible;
                        anyVisible = anyVisible || visible;
                    });
                    group.hidden = !anyVisible;
                });
            });
            empty.hidden = matches.length > 0;
            state.expandedKeys = autoExpandKeys(matches, search.value);
            if (state.expandedKeys.length > 0) {
                state.activeKey = null;
            }
            render();
        }

        roleButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                selectRole(button.getAttribute('data-role-id'));
            });
        });

        select.addEventListener('change', function () {
            selectRole(select.value);
        });

        search.addEventListener('input', applySearch);

        root.addEventListener('click', function (event) {
            var jump = event.target.closest('[data-role-jump]');
            if (jump && root.contains(jump)) {
                var jumpItem = jump.closest('[data-permission-key]');
                selectRole(jump.getAttribute('data-role-jump'));
                // Der angeklickte Chip ist nach dem Neuzeichnen weg; der Fokus bleibt beim selben Recht.
                if (jumpItem) {
                    focusPermission(jumpItem.getAttribute('data-permission-key'));
                }
                return;
            }
            var toggle = event.target.closest('[data-permission-toggle]');
            if (toggle && root.contains(toggle)) {
                var key = toggle.closest('[data-permission-key]').getAttribute('data-permission-key');
                state.activeKey = state.activeKey === key ? null : key;
                state.expandedKeys = [];
                render();
            }
        });

        state.roleId = resolveInitialRoleId(roleIds, global.location ? global.location.hash : '', readStoredRoleId());
        if (state.roleId !== null) {
            render();
        }
    }

    var api = {
        normalizeQuery: normalizeQuery,
        matchPermissions: matchPermissions,
        autoExpandKeys: autoExpandKeys,
        resolveInitialRoleId: resolveInitialRoleId,
    };

    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    } else {
        global.RoleOverviewLogic = api;
        global.document.addEventListener('DOMContentLoaded', function () {
            Array.prototype.forEach.call(global.document.querySelectorAll('[data-role-overview]'), initRoleOverview);
        });
    }
})(typeof window !== 'undefined' ? window : globalThis);
