// Leiste "Als App installieren" oben im Inhalt.
//
// Chromium-Browser (Chrome, Edge, Samsung Internet) melden mit beforeinstallprompt,
// dass sie die Seite installieren können; das Ereignis wird aufgehoben und erst auf
// Knopfdruck ausgelöst. iPhone und iPad kennen keine solche Schnittstelle - dort zeigt
// die Leiste stattdessen den Weg über "Teilen". Wer die App schon geöffnet hat oder
// "Nicht mehr anzeigen" wählt, sieht die Leiste nicht. Der Wunsch liegt im
// localStorage: Der Server braucht ihn nicht zum Rendern.
(function (root) {
    const DISMISS_KEY = 'chormanager.installBanner.dismissed';

    function bannerMode(state) {
        if (state.standalone || state.dismissed) {
            return null;
        }
        if (state.hasPrompt) {
            return 'install';
        }
        return state.ios ? 'ios' : null;
    }

    function isIos(userAgent, maxTouchPoints) {
        if (/iPhone|iPad|iPod/.test(userAgent)) {
            return true;
        }
        // iPadOS meldet sich als Mac; verraten wird es vom Touchscreen.
        return /Macintosh/.test(userAgent) && maxTouchPoints > 1;
    }

    // Gesperrter oder fehlender Speicher (privates Fenster) ist kein Fehler: Die Leiste
    // erscheint dann eben wieder.
    function isDismissed(storage) {
        try {
            return !!storage && storage.getItem(DISMISS_KEY) === '1';
        } catch (e) {
            return false;
        }
    }

    function rememberDismissal(storage) {
        try {
            if (storage) {
                storage.setItem(DISMISS_KEY, '1');
            }
        } catch (e) {
            // Ohne Speicher gilt der Wunsch nur für diese Seite.
        }
    }

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = {
            DISMISS_KEY: DISMISS_KEY,
            bannerMode: bannerMode,
            isIos: isIos,
            isDismissed: isDismissed,
            rememberDismissal: rememberDismissal,
        };
    }

    if (typeof document === 'undefined') {
        return;
    }

    const banner = document.querySelector('[data-install-banner]');
    if (!banner) {
        return;
    }

    const installButton = banner.querySelector('[data-install-banner-install]');
    const iosHint = banner.querySelector('[data-install-banner-ios-hint]');
    const dismissButton = banner.querySelector('[data-install-banner-dismiss]');

    function storage() {
        try {
            return root.localStorage;
        } catch (e) {
            return null;
        }
    }

    function isStandalone() {
        const media = typeof root.matchMedia === 'function' && root.matchMedia('(display-mode: standalone)').matches;
        return media || root.navigator.standalone === true;
    }

    let deferredPrompt = null;

    function render() {
        const mode = bannerMode({
            standalone: isStandalone(),
            dismissed: isDismissed(storage()),
            hasPrompt: deferredPrompt !== null,
            ios: isIos(root.navigator.userAgent || '', root.navigator.maxTouchPoints || 0),
        });

        banner.hidden = mode === null;
        installButton.hidden = mode !== 'install';
        iosHint.hidden = mode !== 'ios';
    }

    root.addEventListener('beforeinstallprompt', function (event) {
        event.preventDefault();
        deferredPrompt = event;
        render();
    });

    root.addEventListener('appinstalled', function () {
        deferredPrompt = null;
        banner.hidden = true;
    });

    installButton.addEventListener('click', function () {
        if (!deferredPrompt) {
            return;
        }
        const promptEvent = deferredPrompt;
        // Ein Ereignis lässt sich nur einmal auslösen.
        deferredPrompt = null;
        promptEvent.prompt();
        promptEvent.userChoice.finally(render);
    });

    dismissButton.addEventListener('click', function () {
        rememberDismissal(storage());
        banner.hidden = true;
    });

    render();
})(typeof window !== 'undefined' ? window : globalThis);
