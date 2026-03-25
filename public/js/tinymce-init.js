document.addEventListener('DOMContentLoaded', function () {
    if (!document.querySelector('.tinymce-editor')) {
        return;
    }

    if (typeof tinymce === 'undefined') {
        return;
    }

    tinymce.init({
        selector: '.tinymce-editor',
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
