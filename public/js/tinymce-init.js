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

        if (tinymce.get(textarea.id)) {
            return;
        }

        tinymce.init({
            selector: '#' + textarea.id,
            language: 'de',
            language_url: '/vendor/tinymce/langs/de.js',
            plugins: 'image link media table lists code fullscreen',
            toolbar: 'undo redo | formatselect | bold italic underline | forecolor | alignleft aligncenter alignright | bullist numlist outdent indent | link image media table | code fullscreen',
            height: 400,
            menubar: 'file edit view insert format tools table help',
            content_style: 'body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; font-size: 14px; }',
            promotion: false,
            setup: function (editor) {
                editor.on('change', function () {
                    tinymce.triggerSave();
                });
            }
        });
    });
}

window.initTinymceEditors = initTinymceEditors;

document.addEventListener('DOMContentLoaded', function () {
    initTinymceEditors(document);
});
