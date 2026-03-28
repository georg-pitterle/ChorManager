(function () {
    document.addEventListener('DOMContentLoaded', function () {
        const table = document.getElementById('evaluationsTable');
        if (!table) {
            return;
        }

        // Shared table engine takes over advanced interactions.
        table.dataset.enhanced = 'true';
    });
})();
