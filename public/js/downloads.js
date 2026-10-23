document.addEventListener('DOMContentLoaded', function () {
    wireMidiFallback();
    wirePlayerToggles();
    wireCopyButtons();
});

/**
 * Hinweis statt Abspieler, wenn der MIDI-Player nicht zur Verfügung steht.
 *
 * Die Skripte stehen als normale script-Tags vor dieser Datei und sind bei
 * DOMContentLoaded geladen oder gescheitert - ein Warten auf gut Glück ist
 * nicht nötig. Auf der Seite ohne MIDI-Datei werden sie gar nicht erst
 * eingebunden, dann gibt es auch keinen Abspieler, der einen Hinweis braucht.
 */
function wireMidiFallback() {
    const midiPlayers = document.querySelectorAll('midi-player');
    if (midiPlayers.length === 0) {
        return;
    }

    function showFallback(player) {
        const fallback = player.parentElement.querySelector('.midi-fallback');
        if (fallback) {
            fallback.classList.remove('d-none');
        }
    }

    const defined = window.customElements && window.customElements.get('midi-player');

    midiPlayers.forEach(function (player) {
        if (!defined) {
            showFallback(player);
            return;
        }

        player.addEventListener('error', function () {
            showFallback(player);
        });
    });
}

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

/**
 * Knopf "Anhören" je Übe-Audio und MIDI-Datei.
 *
 * Die Abspieler stehen verborgen in ihrer Zeile. Ein Lied mit 14 Übe-Audios
 * zeigte früher 14 Abspieler untereinander; jetzt ist immer nur einer offen.
 * Der Knopf klappt ihn auf und startet ihn gleich - wer "Anhören" drückt, will
 * hören, nicht noch einmal auf Play tippen. Ein zweiter Druck hält an und
 * klappt zu, ein anderer Knopf schließt den bisherigen zuerst.
 */
function wirePlayerToggles() {
    const toggles = Array.prototype.slice.call(document.querySelectorAll('[data-player-toggle]'));
    if (toggles.length === 0) {
        return;
    }

    function panelOf(toggle) {
        return document.getElementById(toggle.getAttribute('aria-controls'));
    }

    function stop(panel) {
        const audio = panel.querySelector('audio');
        if (audio) {
            audio.pause();
        }

        const midi = panel.querySelector('midi-player');
        if (midi && typeof midi.stop === 'function' && midi.playing) {
            midi.stop();
        }
    }

    function start(panel) {
        const audio = panel.querySelector('audio');
        if (audio) {
            const playing = audio.play();
            if (playing && typeof playing.catch === 'function') {
                // Blockiert der Browser das Abspielen, bleibt der Abspieler
                // offen und lässt sich von Hand starten.
                playing.catch(function () {});
            }
        }

        const midi = panel.querySelector('midi-player');
        if (midi && typeof midi.start === 'function') {
            midi.start();
        }
    }

    function setState(toggle, open) {
        const panel = panelOf(toggle);
        if (!panel) {
            return;
        }

        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        panel.hidden = !open;

        const icon = toggle.querySelector('i');
        if (icon) {
            icon.className = open ? 'bi bi-stop-fill' : 'bi bi-play-fill';
        }

        const label = toggle.querySelector('[data-player-toggle-label]');
        if (label) {
            label.textContent = open ? 'Stopp' : 'Anhören';
        }

        if (open) {
            start(panel);
        } else {
            stop(panel);
        }
    }

    toggles.forEach(function (toggle) {
        toggle.addEventListener('click', function () {
            const opening = toggle.getAttribute('aria-expanded') !== 'true';

            if (opening) {
                toggles.forEach(function (other) {
                    if (other !== toggle && other.getAttribute('aria-expanded') === 'true') {
                        setState(other, false);
                    }
                });
            }

            setState(toggle, opening);
        });
    });

    // Wer eine Gruppe zuklappt, will dort nichts mehr hören.
    document.querySelectorAll('.downloads-group').forEach(function (group) {
        group.addEventListener('toggle', function () {
            if (group.open) {
                return;
            }

            group.querySelectorAll('[data-player-toggle][aria-expanded="true"]').forEach(function (toggle) {
                setState(toggle, false);
            });
        });
    });
}
