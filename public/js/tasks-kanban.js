(function () {
    var listView = document.getElementById('list-view');
    var kanbanView = document.getElementById('kanban-view');
    var btnList = document.getElementById('btn-view-list');
    var btnKanban = document.getElementById('btn-view-kanban');

    if (!listView || !kanbanView || !btnList || !btnKanban) {
        return;
    }

    function showList() {
        listView.hidden = false;
        kanbanView.hidden = true;
        btnList.classList.add('active');
        btnList.setAttribute('aria-pressed', 'true');
        btnKanban.classList.remove('active');
        btnKanban.setAttribute('aria-pressed', 'false');
        try { sessionStorage.setItem('tasks-view', 'list'); } catch (e) { }
    }

    function showKanban() {
        listView.hidden = true;
        kanbanView.hidden = false;
        kanbanView.classList.add('fade-in');
        btnKanban.classList.add('active');
        btnKanban.setAttribute('aria-pressed', 'true');
        btnList.classList.remove('active');
        btnList.setAttribute('aria-pressed', 'false');
        try { sessionStorage.setItem('tasks-view', 'kanban'); } catch (e) { }
    }

    btnList.addEventListener('click', showList);
    btnKanban.addEventListener('click', showKanban);

    // Restore last view from sessionStorage
    try {
        if (sessionStorage.getItem('tasks-view') === 'kanban') {
            showKanban();
        }
    } catch (e) { }

    // Drag & Drop Logik entfernt, da SortableJS verwendet wird.
}());
