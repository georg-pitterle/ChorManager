document.addEventListener('DOMContentLoaded', function () {
    const midiPlayers = document.querySelectorAll('midi-player');

    setTimeout(function () {
        if (!window.customElements || !window.customElements.get('midi-player')) {
            document.querySelectorAll('.midi-fallback').forEach(function (el) {
                el.classList.remove('d-none');
            });
            return;
        }

        midiPlayers.forEach(function (player) {
            player.addEventListener('error', function () {
                const fallback = player.parentElement.querySelector('.midi-fallback');
                if (fallback) {
                    fallback.classList.remove('d-none');
                }
            });
        });
    }, 1200);

    wireCopyButtons();
});

/**
 * Kopierknöpfe hinter den Zugangsdaten des Noten-Ordners.
 *
 * Über ein Datenattribut statt fester Kennungen: Es sind drei Werte, und das
 * Zugangswort steht nur direkt nach dem Erzeugen überhaupt auf der Seite.
 *
 * `navigator.clipboard` gibt es nur in einem sicheren Kontext. Über http -
 * etwa in einer Testumgebung ohne Zertifikat - fehlt es, und ohne Rückfall
 * täte der Knopf dort nichts, ohne es zu sagen.
 */
function wireCopyButtons() {
    const buttons = document.querySelectorAll('[data-copy-target]');

    buttons.forEach(function (button) {
        const target = document.querySelector(button.dataset.copyTarget);
        if (!target) {
            return;
        }

        const icon = button.querySelector('i');
        const originalIcon = icon ? icon.className : '';
        const originalLabel = button.getAttribute('aria-label') || '';
        let resetTimer = null;

        function report(message, iconClass) {
            if (icon) {
                icon.className = iconClass;
            }
            button.setAttribute('aria-label', message);
            button.setAttribute('title', message);

            window.clearTimeout(resetTimer);
            resetTimer = window.setTimeout(function () {
                if (icon) {
                    icon.className = originalIcon;
                }
                button.setAttribute('aria-label', originalLabel);
                button.setAttribute('title', originalLabel);
            }, 2000);
        }

        function reportResult(copied) {
            report(
                copied ? 'Kopiert' : 'Kopieren nicht möglich',
                copied ? 'bi bi-check-lg' : 'bi bi-exclamation-triangle'
            );
        }

        function copyViaTextarea(text) {
            const helper = document.createElement('textarea');
            helper.value = text;
            helper.setAttribute('readonly', 'readonly');
            helper.classList.add('visually-hidden');
            document.body.appendChild(helper);
            helper.select();

            let copied = false;
            try {
                copied = document.execCommand('copy');
            } catch (error) {
                copied = false;
            }

            document.body.removeChild(helper);

            return copied;
        }

        button.addEventListener('click', function (event) {
            event.preventDefault();

            const text = (target.textContent || '').trim();
            if (text === '') {
                return;
            }

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function () {
                    reportResult(true);
                }).catch(function () {
                    reportResult(copyViaTextarea(text));
                });

                return;
            }

            reportResult(copyViaTextarea(text));
        });
    });
}
