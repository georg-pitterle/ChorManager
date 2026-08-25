let tinymceModalFocusGuardRegistered = false;

// Bootstrap erzwingt im Modal den Fokus und holt ihn bei jedem Fokuswechsel in den Dialog
// zurück. TinyMCE hängt seine Überlauf-Leiste, Menüs und Dialoge jedoch in den Behälter
// .tox-tinymce-aux direkt an das body-Element, also außerhalb des Modals — Bootstrap reißt den
// Fokus daher sofort wieder aus diesen Elementen heraus und macht sie unbedienbar (Überlauf-
// Leiste öffnet sich nicht, Tastatureingaben im Quelltext-Dialog kommen nicht an). Der Beobachter
// fängt Fokus-Ereignisse aus den TinyMCE-Behältern bereits in der Erfassungsphase ab, bevor
// Bootstraps eigener Listener sie zu sehen bekommt. Er wird nur einmal registriert, auch wenn die
// Editor-Initialisierung im Modal-Ladeweg bei jedem Nachladen erneut läuft.
function registerTinymceModalFocusGuard() {
    if (tinymceModalFocusGuardRegistered) {
        return;
    }
    tinymceModalFocusGuardRegistered = true;

    document.addEventListener('focusin', function (event) {
        if (event.target.closest('.tox-tinymce-aux, .tox-dialog, .tox-menu, .tox-toolbar__overflow')) {
            event.stopImmediatePropagation();
        }
    }, true);
}

function initTinymceEditors(root) {
    registerTinymceModalFocusGuard();

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
        const baseContentStyle = 'body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; font-size: 14px; }';
        const baseToolbar = 'undo redo | blocks | bold italic underline | forecolor | alignleft aligncenter alignright | bullist numlist outdent indent | link image media table | code fullscreen';

        // Gestaltung im Newsletter-Inhalt ist auf diese fünf Klassen begrenzt; der Sanitizer
        // lässt nur sie durch, der Mailrenderer übersetzt sie in Inline-Styles. Die Liste muss
        // mit src/Newsletter/ContentClasses.php übereinstimmen. Aufgaben-Editoren bekommen sie
        // nicht: dort gibt es keinen Mailrahmen, der die Klassen definieren würde.
        const contentStyleFormats = [
            { title: 'Einleitung (hervorgehoben)', block: 'p', classes: 'newsletter-lead' },
            { title: 'Nebentext (gedämpft)', block: 'p', classes: 'newsletter-muted' },
            { title: 'Zwischenüberschrift in Markenfarbe', block: 'h3', classes: 'newsletter-accent' },
            { title: 'Zentriert', block: 'p', classes: 'newsletter-center' },
            { title: 'Hinweiskasten', block: 'p', classes: 'newsletter-callout' }
        ];

        // Damit die Klassen schon beim Schreiben so aussehen wie später in der Mail. Die
        // Markenfarbe steht hier nicht zur Verfügung, deshalb der neutrale Akzentton; die echte
        // Farbe setzt der Mailrenderer beim Versand ein.
        const newsletterContentStyle = [
            '.newsletter-lead { font-size: 18px; line-height: 1.6; font-weight: 600; }',
            '.newsletter-muted { font-size: 14px; line-height: 1.6; color: #667085; }',
            '.newsletter-accent { color: #b8860b; }',
            '.newsletter-center { text-align: center; }',
            '.newsletter-callout { padding: 14px 18px; background-color: #f5f7fa;'
                + ' border-left: 4px solid #b8860b; border-radius: 6px; }'
        ].join(' ');

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
            content_style: placeholderSource
                ? baseContentStyle + ' ' + newsletterContentStyle
                : baseContentStyle,
            style_formats_merge: true,
            style_formats: placeholderSource ? contentStyleFormats : undefined,
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
