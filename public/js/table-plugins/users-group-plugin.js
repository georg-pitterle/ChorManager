(function (window, document) {
    if (!window.ChorTableEngine || !window.ChorTableEngine.registerFilterPlugin) {
        return;
    }

    var LS_GROUP_KEY = 'chorte.users.manage.groupByVoice';
    // Gemerkt werden die zugeklappten Gruppen, nicht die offenen: Eine Gruppe
    // steht offen, bis man sie zuklappt. Vorher war alles zu, und nach dem
    // Umschalten schien die Liste verschwunden.
    var LS_CLOSED_KEY = 'chorte.users.manage.accordionClosed';

    window.ChorTableEngine.registerFilterPlugin('usersGroup', function (context) {
        var groupActive = false;
        var stateRef = null;
        var hasExplicitState = false;
        var toggleButtonEl = null;
        var closedBlockIds = new Set();
        var accordionEl = null;
        var observer = null;
        var rafPending = false;

        // Ein Umschalter mit zwei Seiten statt eines Knopfs, der seine Beschriftung
        // wechselt: "Listenansicht" nannte den Zustand, in dem man gerade nicht war.
        function syncToggleButtonLabel() {
            if (!toggleButtonEl) {
                return;
            }
            toggleButtonEl.listButton.classList.toggle('active', !groupActive);
            toggleButtonEl.listButton.setAttribute('aria-pressed', groupActive ? 'false' : 'true');
            toggleButtonEl.voiceButton.classList.toggle('active', groupActive);
            toggleButtonEl.voiceButton.setAttribute('aria-pressed', groupActive ? 'true' : 'false');
        }

        function readLocalStorage() {
            try {
                var val = window.localStorage.getItem(LS_GROUP_KEY);
                groupActive = val === '1';
                var closedVal = window.localStorage.getItem(LS_CLOSED_KEY);
                if (closedVal) {
                    var arr = JSON.parse(closedVal);
                    if (Array.isArray(arr)) {
                        closedBlockIds = new Set(arr);
                    }
                }
            } catch (e) {
                groupActive = false;
                closedBlockIds = new Set();
            }
        }

        function persistGroupActive() {
            try {
                if (groupActive) {
                    window.localStorage.setItem(LS_GROUP_KEY, '1');
                } else {
                    window.localStorage.removeItem(LS_GROUP_KEY);
                }
            } catch (e) { /* quota exceeded — silently ignore */ }
        }

        function persistClosedBlocks() {
            try {
                window.localStorage.setItem(LS_CLOSED_KEY, JSON.stringify(Array.from(closedBlockIds)));
            } catch (e) { /* quota exceeded */ }
        }

        function parseVoiceOptions(raw) {
            var result = [];
            var seen = {};
            if (typeof raw !== 'string' || !raw.trim()) return result;
            raw.split('||').forEach(function (entry) {
                entry = entry.trim();
                if (!entry) return;
                var sep = entry.indexOf('::');
                if (sep === -1) return;
                var id = entry.slice(0, sep).trim();
                var name = entry.slice(sep + 2).trim();
                if (!id || !name || seen[id]) return;
                seen[id] = true;
                result.push({ id: id, name: name });
            });
            return result;
        }

        function parseSubVoiceOptions(raw) {
            // Returns map: voiceGroupId -> [{id, name}]
            var map = {};
            if (typeof raw !== 'string' || !raw.trim()) return map;
            raw.split('||').forEach(function (entry) {
                entry = entry.trim();
                if (!entry) return;
                var sep = entry.indexOf('::');
                if (sep === -1) return;
                var left = entry.slice(0, sep).trim();
                var name = entry.slice(sep + 2).trim();
                var colonIdx = left.indexOf(':');
                if (colonIdx === -1) return;
                var vgId = left.slice(0, colonIdx).trim();
                var svId = left.slice(colonIdx + 1).trim();
                if (!vgId || !svId || !name) return;
                if (!map[vgId]) map[vgId] = [];
                map[vgId].push({ id: svId, name: name });
            });
            return map;
        }

        function getTableShell(slot) {
            if (slot && typeof slot.closest === 'function') {
                var shell = slot.closest('[data-table-engine="true"]');
                if (shell) {
                    return shell;
                }
            }

            // Fallback for lightweight test DOM stubs that don't fully support attribute selectors.
            var node = slot;
            while (node) {
                if (node.dataset && node.dataset.tableEngine === 'true') {
                    return node;
                }
                node = node._parent || null;
            }
            return null;
        }

        function findFirstByTag(root, tagName) {
            if (!root || !tagName) return null;
            var target = String(tagName).toUpperCase();
            var queue = [root];
            while (queue.length > 0) {
                var node = queue.shift();
                if (!node || !node.children) continue;
                for (var i = 0; i < node.children.length; i++) {
                    var child = node.children[i];
                    if (child && child.tagName === target) {
                        return child;
                    }
                    queue.push(child);
                }
            }
            return null;
        }

        function queryFirstOrTag(root, selector, tagName) {
            if (root && typeof root.querySelector === 'function') {
                var found = root.querySelector(selector);
                if (found) return found;
            }
            return findFirstByTag(root, tagName);
        }

        function collectRowsFromTbody(tbody) {
            if (!tbody) return [];
            var rows = [];
            var queue = [tbody];
            while (queue.length > 0) {
                var node = queue.shift();
                if (!node || !node.children) continue;
                for (var i = 0; i < node.children.length; i++) {
                    var child = node.children[i];
                    if (!child) continue;
                    if (child.tagName === 'TR') {
                        rows.push(child);
                    }
                    queue.push(child);
                }
            }
            return rows;
        }

        function getVisibleRows(tableShell) {
            var tbody = queryFirstOrTag(tableShell, 'tbody', 'tbody');
            if (!tbody) return [];
            var all = typeof tbody.querySelectorAll === 'function'
                ? Array.prototype.slice.call(tbody.querySelectorAll('tr'))
                : [];
            if (all.length === 0) {
                all = collectRowsFromTbody(tbody);
            }
            return all.filter(function (r) { return !r.hidden; });
        }

        function getAllRows(tableShell) {
            var tbody = queryFirstOrTag(tableShell, 'tbody', 'tbody');
            if (!tbody) return [];
            var all = typeof tbody.querySelectorAll === 'function'
                ? Array.prototype.slice.call(tbody.querySelectorAll('tr'))
                : [];
            if (all.length === 0) {
                all = collectRowsFromTbody(tbody);
            }
            return all;
        }

        function matchSubVoice(row, subVoices) {
            var sortVoice = (row.dataset && row.dataset.sortVoice) ? row.dataset.sortVoice.toLowerCase() : '';
            if (!sortVoice) return null;
            for (var i = 0; i < subVoices.length; i++) {
                if (sortVoice.indexOf(subVoices[i].name.toLowerCase()) !== -1) {
                    return subVoices[i];
                }
            }
            return null;
        }

        function rowBelongsToVoiceGroup(row, vgId) {
            var voice = (row.dataset && row.dataset.voice) ? row.dataset.voice : '';
            return voice.indexOf('|' + vgId + '|') !== -1;
        }

        function makeCollapseId(prefix, id) {
            return 'ug-' + prefix + '-' + id;
        }

        function createAccordionItem(headerId, collapseId, title, contentEl, isOpen, memberCount) {
            var item = document.createElement('div');
            item.className = 'accordion-item';

            var header = document.createElement('h2');
            header.className = 'accordion-header';
            header.id = headerId;

            var button = document.createElement('button');
            button.className = 'accordion-button' + (isOpen ? '' : ' collapsed');
            button.type = 'button';
            button.dataset = button.dataset || {};
            button.dataset['bsToggle'] = 'collapse';
            button.dataset['bsTarget'] = '#' + collapseId;
            button.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            button.setAttribute('aria-controls', collapseId);

            var titleEl = document.createElement('span');
            titleEl.textContent = title;
            button.appendChild(titleEl);

            if (typeof memberCount === 'number') {
                var countBadge = document.createElement('span');
                countBadge.className = 'badge text-bg-light border';
                countBadge.dataset = countBadge.dataset || {};
                countBadge.dataset['usersGroupCountBadge'] = collapseId;
                countBadge.textContent = String(memberCount);
                // Keep count badge right next to the built-in chevron regardless of title width.
                countBadge.style.position = 'absolute';
                countBadge.style.right = '2.75rem';
                countBadge.style.top = '50%';
                countBadge.style.transform = 'translateY(-50%)';
                button.appendChild(countBadge);
            }
            header.appendChild(button);

            var collapseDiv = document.createElement('div');
            collapseDiv.id = collapseId;
            collapseDiv.className = 'accordion-collapse collapse' + (isOpen ? ' show' : '');
            collapseDiv.dataset = collapseDiv.dataset || {};
            collapseDiv.dataset['blockId'] = collapseId;

            var body = document.createElement('div');
            body.className = 'accordion-body p-0';
            body.appendChild(contentEl);
            collapseDiv.appendChild(body);

            item.appendChild(header);
            item.appendChild(collapseDiv);

            return item;
        }

        function cloneTableForRows(originalTable, rows) {
            var table = document.createElement('table');
            table.className = (originalTable && originalTable.className) ? originalTable.className : 'table table-hover table-striped mb-0';

            var thead = originalTable && typeof originalTable.querySelector === 'function'
                ? originalTable.querySelector('thead')
                : null;
            if (thead) {
                var headClone = thead.cloneNode(true);
                // Der Kopf der Gesamttabelle ist im Gruppenmodus ausgeblendet;
                // die Kopie gehört sichtbar über ihre Gruppe.
                headClone.hidden = false;
                table.appendChild(headClone);
            }

            var tbody = document.createElement('tbody');
            rows.forEach(function (row) {
                tbody.appendChild(row.cloneNode(true));
            });
            table.appendChild(tbody);

            var wrapper = document.createElement('div');
            wrapper.className = 'table-responsive';
            wrapper.appendChild(table);
            return wrapper;
        }

        function buildAccordion(tableShell) {
            var voiceOptions = parseVoiceOptions(
                tableShell.dataset ? tableShell.dataset.voiceOptions : ''
            );
            var subVoiceMap = parseSubVoiceOptions(
                tableShell.dataset ? tableShell.dataset.subVoiceOptions : ''
            );
            var visibleRows = getVisibleRows(tableShell);
            var originalTable = queryFirstOrTag(tableShell, 'table', 'table');

            var accordion = document.createElement('div');
            accordion.className = 'accordion users-group-accordion my-3';

            // Wire collapse events for persistence
            accordion.addEventListener('show.bs.collapse', function (e) {
                var blockId = e.target && e.target.dataset ? e.target.dataset.blockId : null;
                if (blockId) {
                    closedBlockIds.delete(blockId);
                    persistClosedBlocks();
                }
            });
            accordion.addEventListener('hide.bs.collapse', function (e) {
                var blockId = e.target && e.target.dataset ? e.target.dataset.blockId : null;
                if (blockId) {
                    closedBlockIds.add(blockId);
                    persistClosedBlocks();
                }
            });

            var hasAny = false;

            voiceOptions.forEach(function (vg) {
                var vgRows = visibleRows.filter(function (r) {
                    return rowBelongsToVoiceGroup(r, vg.id);
                });
                if (vgRows.length === 0) return;

                hasAny = true;
                var vgCollapseId = makeCollapseId('vg', vg.id);
                var isVgOpen = !closedBlockIds.has(vgCollapseId);
                var subVoices = subVoiceMap[vg.id] || [];

                var innerAccordion = document.createElement('div');
                innerAccordion.className = 'accordion accordion-flush';

                // Sub-group: rows without a matching subvoice
                var unassignedRows = vgRows.filter(function (r) {
                    return subVoices.length === 0 || matchSubVoice(r, subVoices) === null;
                });
                if (unassignedRows.length > 0) {
                    var noSvId = makeCollapseId('nosv', vg.id);
                    var isNoSvOpen = !closedBlockIds.has(noSvId);
                    var noSvContent = cloneTableForRows(originalTable, unassignedRows);
                    var noSvItem = createAccordionItem(
                        'hdr-' + noSvId, noSvId,
                        subVoices.length > 0 ? 'Ohne Unterstimme' : vg.name,
                        noSvContent, isNoSvOpen, unassignedRows.length
                    );
                    innerAccordion.appendChild(noSvItem);
                }

                subVoices.forEach(function (sv) {
                    var svRows = vgRows.filter(function (r) {
                        var matched = matchSubVoice(r, subVoices);
                        return matched && matched.id === sv.id;
                    });
                    if (svRows.length === 0) return;

                    var svCollapseId = makeCollapseId('sv', sv.id);
                    var isSvOpen = !closedBlockIds.has(svCollapseId);
                    var svContent = cloneTableForRows(originalTable, svRows);
                    var svItem = createAccordionItem(
                        'hdr-' + svCollapseId, svCollapseId,
                        sv.name, svContent, isSvOpen, svRows.length
                    );
                    innerAccordion.appendChild(svItem);
                });

                var vgContent = document.createElement('div');
                vgContent.className = 'p-2';
                vgContent.appendChild(innerAccordion);

                var vgItem = createAccordionItem(
                    'hdr-' + vgCollapseId, vgCollapseId,
                    vg.name, vgContent, isVgOpen, vgRows.length
                );

                // If subVoices is empty AND unassigned rows filled it, add accordion directly
                if (subVoices.length === 0) {
                    // Replace inner accordion with just the table
                    var directContent = cloneTableForRows(originalTable, unassignedRows);
                    vgContent = document.createElement('div');
                    vgContent.appendChild(directContent);
                    vgItem = createAccordionItem(
                        'hdr-' + vgCollapseId, vgCollapseId,
                        vg.name, vgContent, isVgOpen, unassignedRows.length
                    );
                }

                accordion.appendChild(vgItem);
            });

            // "Ohne Zuordnung" block
            var noGroupRows = visibleRows.filter(function (r) {
                var voice = r.dataset && r.dataset.voice ? r.dataset.voice : '';
                return voice === '' || voice === '||' || voice === '|';
            });
            if (noGroupRows.length > 0) {
                hasAny = true;
                var ngId = 'ug-no-group';
                var isNgOpen = !closedBlockIds.has(ngId);
                var ngContent = cloneTableForRows(originalTable, noGroupRows);
                var ngItem = createAccordionItem('hdr-' + ngId, ngId, 'Ohne Zuordnung', ngContent, isNgOpen, noGroupRows.length);
                accordion.appendChild(ngItem);
            }

            if (!hasAny) {
                var emptyMsg = document.createElement('p');
                emptyMsg.className = 'text-muted p-3 mb-0';
                emptyMsg.textContent = 'Keine Mitglieder gefunden.';
                accordion.appendChild(emptyMsg);
            }

            return accordion;
        }

        function setHeadHidden(tableShell, hidden) {
            var table = queryFirstOrTag(tableShell, 'table', 'table');
            var thead = table ? queryFirstOrTag(table, 'thead', 'thead') : null;
            if (thead) thead.hidden = hidden;
        }

        function destroyAccordion(tableShell) {
            if (accordionEl && accordionEl.remove) {
                accordionEl.remove();
            }
            accordionEl = null;

            // Re-show tbody
            var tbody = queryFirstOrTag(tableShell, 'tbody', 'tbody');
            if (tbody) tbody.hidden = false;
            setHeadHidden(tableShell, false);
        }

        function activateGroup(tableShell) {
            var tbody = queryFirstOrTag(tableShell, 'tbody', 'tbody');
            if (tbody) tbody.hidden = true;
            // Jede Gruppe bringt ihren eigenen Tabellenkopf mit; der Kopf der
            // ausgeblendeten Gesamttabelle stand sonst leer über allen Gruppen.
            setHeadHidden(tableShell, true);

            if (accordionEl) {
                if (accordionEl.remove) accordionEl.remove();
                accordionEl = null;
            }

            accordionEl = buildAccordion(tableShell);
            var table = queryFirstOrTag(tableShell, 'table', 'table');
            // `_parent` gibt es nur in den Test-Attrappen; im echten DOM heißt es
            // parentNode. Ohne diesen Zweig landeten die Gruppen am Ende des
            // Rahmens - hinter der unteren Blätterleiste.
            var tableParent = table ? (table._parent || table.parentNode) : null;
            if (tableParent && tableParent.insertBefore) {
                tableParent.insertBefore(accordionEl, table);
            } else if (tableShell.appendChild) {
                tableShell.appendChild(accordionEl);
            }

            // Watch tbody so an outside filter/sort/page change (which flips `hidden`
            // on rows) resyncs the accordion. The observer is created once and reused:
            // re-creating it here on every rebuild left the previous instance running,
            // and destroy+rebuild itself flips tbody's own `hidden` attribute, which
            // that still-active instance would pick up as "the filter changed again" -
            // an infinite rebuild loop that made the accordion unusable after the first
            // real filter change (e.g. picking a project while grouped by voice).
            if (tbody && typeof window.MutationObserver === 'function') {
                if (!observer) {
                    observer = new window.MutationObserver(function () {
                        if (!rafPending) {
                            rafPending = true;
                            window.requestAnimationFrame(function () {
                                rafPending = false;
                                if (!groupActive) {
                                    return;
                                }
                                if (observer) {
                                    observer.disconnect();
                                }
                                destroyAccordion(tableShell);
                                activateGroup(tableShell);
                            });
                        }
                    });
                }
                observer.observe(tbody, { attributes: true, subtree: true, attributeFilter: ['hidden'] });
            }
        }

        function deactivateGroup(tableShell) {
            if (observer) {
                observer.disconnect();
                observer = null;
            }
            destroyAccordion(tableShell);
        }

        function mount() {
            var tableShell = getTableShell(context.pluginSlot);
            var showArchived = tableShell && tableShell.dataset
                ? tableShell.dataset.showArchived === '1'
                : false;

            if (showArchived) return;

            if (!hasExplicitState) {
                readLocalStorage();
            }

            var btn = document.createElement('div');
            btn.className = 'btn-group btn-group-sm view-switch';
            btn.setAttribute('role', 'group');
            btn.setAttribute('aria-label', 'Darstellung der Liste');

            function makeSide(label, active) {
                var side = document.createElement('button');
                side.type = 'button';
                side.className = 'btn btn-outline-secondary';
                side.textContent = label;
                side.addEventListener('click', function () {
                    if (groupActive === active) {
                        return;
                    }
                    setGroupActive(active);
                });
                btn.appendChild(side);
                return side;
            }

            var listButton = makeSide('Liste', false);
            var voiceButton = makeSide('Nach Stimme', true);
            voiceButton.dataset = voiceButton.dataset || {};
            voiceButton.dataset['usersGroupToggle'] = '';
            toggleButtonEl = { listButton: listButton, voiceButton: voiceButton };
            syncToggleButtonLabel();

            function setGroupActive(next) {
                groupActive = next;
                persistGroupActive();
                syncToggleButtonLabel();
                if (groupActive) {
                    activateGroup(tableShell);
                } else {
                    deactivateGroup(tableShell);
                }
            }

            // Gruppieren ändert die Darstellung, nicht die Treffermenge: Der
            // Umschalter steht neben dem Menü "Ansicht", nicht zwischen den Filtern.
            var slot = context.viewSlot || context.pluginSlot;
            if (slot && slot.appendChild) {
                slot.appendChild(btn);
            }

            if (groupActive && tableShell) {
                activateGroup(tableShell);
            }
        }

        return {
            mount: mount,
            getPredicate: function () { return null; },
            setState: function (nextState) {
                if (nextState && typeof nextState.groupActive === 'boolean') {
                    groupActive = nextState.groupActive;
                    stateRef = nextState;
                    hasExplicitState = true;
                }
            },
            getState: function () {
                if (stateRef && typeof stateRef === 'object') {
                    stateRef.groupActive = groupActive;
                    return stateRef;
                }
                return { groupActive: groupActive };
            },
            reset: function () {
                var tableShell = getTableShell(context.pluginSlot);
                groupActive = false;
                hasExplicitState = false;
                if (stateRef && typeof stateRef === 'object') {
                    stateRef.groupActive = false;
                }
                closedBlockIds = new Set();
                try {
                    window.localStorage.removeItem(LS_GROUP_KEY);
                    window.localStorage.removeItem(LS_CLOSED_KEY);
                } catch (e) { /* ignore */ }
                syncToggleButtonLabel();
                if (tableShell) {
                    deactivateGroup(tableShell);
                }
            }
        };
    });
})(window, document);
