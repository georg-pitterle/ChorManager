document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.vg-checkbox').forEach(function(checkbox) {
        checkbox.addEventListener('change', function() {
            var container = this.closest('.border');
            var selector = container.querySelector('.collapse-sv');
            if (this.checked) {
                selector.style.display = 'block';
            } else {
                selector.style.display = 'none';
                selector.querySelector('select').value = '';
            }
        });
    });
});
