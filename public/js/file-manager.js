/**
 * Ordnerseite der Dateiverwaltung: Hochladen per Ziehen oder Auswahl, die
 * geteilten Umbenennen-/Verschieben-Modals, die Versionsliste und das
 * Hinzufügen von Freigabe-Zeilen.
 *
 * Jede Datei geht in einer eigenen Anfrage hoch. So bekommt jede ihren eigenen
 * Fortschritt und ihre eigene Fehlermeldung, und eine zu große Datei bricht
 * nicht den Rest ab.
 */
(function () {
    'use strict';

    const csrfToken = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    function formatSize(bytes) {
        const value = Number(bytes) || 0;
        if (value >= 1048576) {
            return (value / 1048576).toLocaleString('de-AT', { maximumFractionDigits: 1 }) + ' MB';
        }
        if (value >= 1024) {
            return Math.round(value / 1024).toLocaleString('de-AT') + ' KB';
        }
        return value + ' B';
    }

    function setupUpload() {
        const zone = document.querySelector('[data-files-dropzone]');
        if (!zone) {
            return;
        }

        const input = zone.querySelector('[data-files-input]');
        const list = zone.querySelector('[data-files-progress]');
        const url = zone.getAttribute('data-upload-url');
        const maxBytes = Number(zone.getAttribute('data-max-bytes')) || 0;
        let pending = 0;
        let succeeded = 0;

        document.querySelectorAll('[data-files-pick]').forEach(function (button) {
            button.addEventListener('click', function () {
                input.click();
            });
        });

        input.addEventListener('change', function () {
            uploadAll(input.files);
            input.value = '';
        });

        ['dragenter', 'dragover'].forEach(function (type) {
            zone.addEventListener(type, function (event) {
                event.preventDefault();
                zone.classList.add('is-dragover');
            });
        });
        ['dragleave', 'drop'].forEach(function (type) {
            zone.addEventListener(type, function (event) {
                event.preventDefault();
                zone.classList.remove('is-dragover');
            });
        });
        zone.addEventListener('drop', function (event) {
            if (event.dataTransfer && event.dataTransfer.files) {
                uploadAll(event.dataTransfer.files);
            }
        });

        function uploadAll(files) {
            Array.prototype.forEach.call(files, uploadOne);
        }

        function addRow(name) {
            const item = document.createElement('li');
            item.className = 'files-upload-item';
            const label = document.createElement('div');
            label.className = 'small text-truncate';
            label.textContent = name;
            const progress = document.createElement('div');
            progress.className = 'progress files-upload-progress';
            const bar = document.createElement('div');
            bar.className = 'progress-bar';
            progress.appendChild(bar);
            const status = document.createElement('div');
            status.className = 'small text-muted';
            item.appendChild(label);
            item.appendChild(progress);
            item.appendChild(status);
            list.appendChild(item);
            return { item: item, bar: bar, status: status };
        }

        function finish(row, ok, message) {
            row.bar.classList.add(ok ? 'bg-success' : 'bg-danger');
            row.bar.style.setProperty('--progress-value', '100%');
            row.status.textContent = message;
            row.status.classList.toggle('text-danger', !ok);
            pending -= 1;
            if (ok) {
                succeeded += 1;
            }
            if (pending === 0 && succeeded > 0) {
                // Kurz stehen lassen, damit die Rückmeldung lesbar ist.
                window.setTimeout(function () {
                    window.location.reload();
                }, 900);
            }
        }

        function uploadOne(file) {
            pending += 1;
            const row = addRow(file.name);

            if (maxBytes > 0 && file.size > maxBytes) {
                finish(row, false, 'Zu groß (' + formatSize(file.size) + ', höchstens ' + formatSize(maxBytes) + ').');
                return;
            }

            const data = new FormData();
            data.append('file', file);

            const request = new XMLHttpRequest();
            request.open('POST', url);
            request.setRequestHeader('X-CSRF-Token', csrfToken);
            request.setRequestHeader('Accept', 'application/json');
            request.upload.addEventListener('progress', function (event) {
                if (event.lengthComputable) {
                    const percent = Math.round((event.loaded / event.total) * 100);
                    row.bar.style.setProperty('--progress-value', percent + '%');
                }
            });
            request.addEventListener('load', function () {
                let payload = null;
                try {
                    payload = JSON.parse(request.responseText);
                } catch (error) {
                    payload = null;
                }
                if (request.status >= 200 && request.status < 300 && payload && payload.ok) {
                    finish(row, true, payload.new_version ? 'Als neue Version gespeichert.' : 'Hochgeladen.');
                    return;
                }
                const message = payload && payload.error
                    ? payload.error
                    : (request.status === 413 ? 'Die Datei ist zu groß.' : 'Hochladen fehlgeschlagen.');
                finish(row, false, message);
            });
            request.addEventListener('error', function () {
                finish(row, false, 'Verbindung unterbrochen.');
            });
            request.send(data);
        }
    }

    function setupSharedModals() {
        const renameModal = document.getElementById('filesRenameModal');
        const moveModal = document.getElementById('filesMoveModal');

        document.addEventListener('click', function (event) {
            const rename = event.target.closest('[data-files-rename]');
            if (rename && renameModal) {
                const form = renameModal.querySelector('[data-files-rename-form]');
                form.setAttribute('action', rename.getAttribute('data-action'));
                form.querySelector('input[name="name"]').value = rename.getAttribute('data-name') || '';
                window.bootstrap.Modal.getOrCreateInstance(renameModal).show();
                return;
            }

            const move = event.target.closest('[data-files-move]');
            if (move && moveModal) {
                moveModal.querySelector('[data-files-move-form]').setAttribute('action', move.getAttribute('data-action'));
                window.bootstrap.Modal.getOrCreateInstance(moveModal).show();
            }
        });

        if (renameModal) {
            renameModal.addEventListener('shown.bs.modal', function () {
                const field = renameModal.querySelector('input[name="name"]');
                field.focus();
                // Den Namen ohne Endung markieren - meist ändert man nur ihn.
                const dot = field.value.lastIndexOf('.');
                field.setSelectionRange(0, dot > 0 ? dot : field.value.length);
            });
        }
    }

    function setupVersions() {
        const modal = document.getElementById('filesVersionsModal');
        if (!modal) {
            return;
        }
        const body = modal.querySelector('[data-files-versions-body]');
        const title = modal.querySelector('.modal-title');

        function message(text) {
            body.textContent = '';
            const paragraph = document.createElement('p');
            paragraph.className = 'text-muted mb-0';
            paragraph.textContent = text;
            body.appendChild(paragraph);
        }

        function render(data, canRestore) {
            body.textContent = '';
            const list = document.createElement('div');
            list.className = 'list-group';
            data.versions.forEach(function (version) {
                const row = document.createElement('div');
                row.className = 'list-group-item d-flex justify-content-between align-items-center gap-2 flex-wrap';

                const info = document.createElement('div');
                const heading = document.createElement('strong');
                heading.textContent = 'Version ' + version.number;
                info.appendChild(heading);
                if (version.id === data.current_version_id) {
                    const badge = document.createElement('span');
                    badge.className = 'badge bg-success ms-2';
                    badge.textContent = 'aktuell';
                    info.appendChild(badge);
                }
                const meta = document.createElement('div');
                meta.className = 'small text-muted';
                meta.textContent = [version.created_at, version.uploaded_by, formatSize(version.size)]
                    .filter(Boolean).join(' · ');
                info.appendChild(meta);
                row.appendChild(info);

                const actions = document.createElement('div');
                actions.className = 'd-flex gap-1';
                const download = document.createElement('a');
                download.className = 'btn btn-sm btn-outline-secondary';
                download.href = '/files/versions/' + version.id + '/download';
                download.textContent = 'Herunterladen';
                actions.appendChild(download);

                if (canRestore && version.id !== data.current_version_id) {
                    const form = document.createElement('form');
                    form.method = 'post';
                    form.action = '/files/versions/' + version.id + '/restore';
                    const token = document.createElement('input');
                    token.type = 'hidden';
                    token.name = '_csrf';
                    token.value = body.getAttribute('data-csrf') || csrfToken;
                    form.appendChild(token);
                    const button = document.createElement('button');
                    button.type = 'submit';
                    button.className = 'btn btn-sm btn-outline-primary';
                    button.textContent = 'Wiederherstellen';
                    form.appendChild(button);
                    actions.appendChild(form);
                }
                row.appendChild(actions);
                list.appendChild(row);
            });
            body.appendChild(list);
        }

        document.addEventListener('click', function (event) {
            const trigger = event.target.closest('[data-files-versions]');
            if (!trigger) {
                return;
            }
            title.textContent = 'Versionen: ' + (trigger.getAttribute('data-name') || '');
            message('Wird geladen …');
            window.bootstrap.Modal.getOrCreateInstance(modal).show();

            fetch(trigger.getAttribute('data-versions-url'), { headers: { Accept: 'application/json' } })
                .then(function (response) {
                    return response.json();
                })
                .then(function (data) {
                    if (!data || !data.ok) {
                        message((data && data.error) || 'Die Versionen konnten nicht geladen werden.');
                        return;
                    }
                    render(data, trigger.getAttribute('data-can-restore') === '1');
                })
                .catch(function () {
                    message('Die Versionen konnten nicht geladen werden.');
                });
        });
    }

    function setupShares() {
        const form = document.querySelector('[data-files-shares-form]');
        if (!form) {
            return;
        }
        const rows = form.querySelector('[data-files-share-rows]');
        const template = form.querySelector('[data-files-share-template]');
        let counter = rows.querySelectorAll('[data-files-share-row]').length;

        form.querySelector('[data-files-share-add]').addEventListener('click', function () {
            const row = template.content.firstElementChild.cloneNode(true);
            const index = 'n' + counter;
            counter += 1;
            row.querySelectorAll('[name]').forEach(function (field) {
                field.setAttribute('name', field.getAttribute('name').split('__INDEX__').join(index));
            });
            rows.appendChild(row);
        });

        rows.addEventListener('click', function (event) {
            const remove = event.target.closest('[data-files-share-remove]');
            if (remove) {
                remove.closest('[data-files-share-row]').remove();
            }
        });
    }

    /**
     * Die Tabelle steckt in .table-responsive, das per overflow abschneidet - das
     * Aktionsmenü der letzten Zeilen verschwand darunter. Mit der Strategie
     * "fixed" richtet Popper das Menü am Fenster aus statt am Tabellenrahmen
     * (dasselbe Vorgehen wie in newsletters.js).
     */
    function setupRowMenus() {
        if (!window.bootstrap || !window.bootstrap.Dropdown) {
            return;
        }
        document.querySelectorAll('.files-table [data-bs-toggle="dropdown"]').forEach(function (toggle) {
            const existing = window.bootstrap.Dropdown.getInstance(toggle);
            if (existing) {
                existing.dispose();
            }
            new window.bootstrap.Dropdown(toggle, {
                boundary: 'viewport',
                popperConfig: function (defaultConfig) {
                    return Object.assign({}, defaultConfig, { strategy: 'fixed' });
                },
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        setupRowMenus();
        setupUpload();
        setupSharedModals();
        setupVersions();
        setupShares();
    });
})();
