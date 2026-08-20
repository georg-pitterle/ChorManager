function initTinymceEditors(root) {
    const scope = root && typeof root.querySelectorAll === 'function' ? root : document;
    const editors = scope.querySelectorAll('.tinymce-editor');

    if (!editors.length || typeof tinymce === 'undefined') {
        return;
    }

    editors.forEach(function (textarea, index) {
        if (!textarea.id) {
            textarea.id = 'tinymce-editor-' + Date.now() + '-' + index;
        }

        const existingEditor = tinymce.get(textarea.id);
        if (existingEditor) {
            // Reused IDs in modal workflows can point to a detached editor instance.
            // Keep only the editor bound to this exact textarea element.
            if (existingEditor.targetElm === textarea) {
                return;
            }

            existingEditor.remove();
        }

        const placeholderSource = textarea.dataset.placeholderSource || '';
        const baseToolbar = 'undo redo | blocks | bold italic underline | forecolor | alignleft aligncenter alignright | bullist numlist outdent indent | link image media table | code fullscreen';

        tinymce.init({
            license_key: 'gpl',
            selector: '#' + textarea.id,
            language: 'de',
            language_url: '/vendor/tinymce/langs/de.js',
            plugins: 'image link media table lists code fullscreen',
            // Der Platzhalter-Knopf steht bewusst weit vorne: hinten angehängt landet er bei
            // üblichen Fensterbreiten im Überlauf-Menü und ist damit praktisch unsichtbar.
            toolbar: placeholderSource
                ? baseToolbar.replace('| blocks |', '| blocks | placeholders |')
                : baseToolbar,
            height: 400,
            menubar: 'file edit view insert format tools table help',
            content_style: 'body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; font-size: 14px; }',
            promotion: false,
            setup: function (editor) {
                editor.on('change', function () {
                    tinymce.triggerSave();
                });

                if (!placeholderSource) {
                    return;
                }

                let placeholders = [];

                editor.on('init', function () {
                    fetch(placeholderSource, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                        .then(function (response) {
                            return response.ok ? response.json() : { placeholders: [] };
                        })
                        .then(function (data) {
                            placeholders = Array.isArray(data.placeholders) ? data.placeholders : [];
                        })
                        .catch(function () {
                            placeholders = [];
                        });
                });

                editor.ui.registry.addMenuButton('placeholders', {
                    text: 'Platzhalter',
                    tooltip: 'Platzhalter einfügen',
                    fetch: function (callback) {
                        callback(placeholders.map(function (placeholder) {
                            return {
                                type: 'menuitem',
                                text: placeholder.label + ' — ' + placeholder.token,
                                onAction: function () {
                                    // Als reiner Text einfügen: Formatierung innerhalb der
                                    // Klammern würde die Ersetzung beim Versand verhindern.
                                    editor.insertContent(placeholder.token);
                                }
                            };
                        }));
                    }
                });
            }
        });
    });
}

window.initTinymceEditors = initTinymceEditors;

document.addEventListener('DOMContentLoaded', function () {
    initTinymceEditors(document);
});
