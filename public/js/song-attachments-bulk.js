// Mehrere Liedanhänge auf einmal löschen.
//
// Die Checkboxen stehen in der Liste und gehören über `form=` zum Sammelformular darüber;
// jede Zeile trägt schon ihr eigenes Lösch-Formular, und Formulare lassen sich nicht
// verschachteln. Die Rückfrage selbst stellt common.js aus data-confirm.
(function () {
    function confirmText(count) {
        const attachments = count === 1 ? '1 Anhang' : count + ' Anhänge';

        return attachments + ' wirklich löschen? Das lässt sich nicht rückgängig machen.';
    }

    function buttonLabel(count) {
        return count > 0 ? 'Ausgewählte löschen (' + count + ')' : 'Ausgewählte löschen';
    }

    function bind() {
        const form = document.getElementById('song-attachments-bulk-form');
        if (!form) {
            return;
        }

        const selectAll = document.getElementById('song-attachments-select-all');
        const button = form.querySelector('button[type="submit"]');
        const label = button.querySelector('span');
        const boxes = Array.from(document.querySelectorAll('.song-attachment-select'));

        function sync() {
            const count = boxes.filter(box => box.checked).length;

            selectAll.checked = count === boxes.length;
            selectAll.indeterminate = count > 0 && count < boxes.length;
            button.disabled = count === 0;
            label.textContent = buttonLabel(count);
            form.setAttribute('data-confirm', confirmText(count));
        }

        selectAll.addEventListener('change', function () {
            boxes.forEach(box => {
                box.checked = selectAll.checked;
            });
            sync();
        });
        boxes.forEach(box => box.addEventListener('change', sync));

        // Der Browser stellt angehakte Felder nach dem Zurückgehen wieder her.
        sync();
    }

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = { confirmText: confirmText, buttonLabel: buttonLabel };
    }
    if (typeof document !== 'undefined') {
        document.addEventListener('DOMContentLoaded', bind);
    }
}());
