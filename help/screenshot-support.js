'use strict';

/**
 * Gemeinsame Vorbereitung für die Screenshot-Skripte unter help/{slug}/scripts/.
 *
 * Die Seitenleiste ist ab 992 px position: fixed und so hoch wie das Fenster. Zwei
 * Eigenheiten von page.screenshot({ fullPage: true }) ergeben damit falsche Bilder,
 * obwohl die Seite im Browser stimmt:
 *
 * 1. Chromium zeichnet feste Elemente nur in Fensterhöhe. Bei langen Seiten endete die
 *    dunkle Leiste deshalb mitten im Bild. Ein Streifen in Leistenbreite am Body setzt
 *    sie optisch bis zum Seitenende fort.
 * 2. Für die Aufnahme ändert Chromium kurz die Fenstermaße. Die Breiten-Animation der
 *    Leiste lief dabei an und wurde mitten im Lauf fotografiert - die Leiste erschien
 *    schmaler, die Einträge abgeschnitten. Für Screenshots sind die Übergänge aus.
 *
 * Nur für Screenshots: Der Stil wird per addInitScript in jede Seite des Kontexts
 * gehängt, die App selbst bleibt unverändert.
 */
const SCREENSHOT_CSS = `
@media (min-width: 992px) {
    body.app-shell--with-sidebar {
        background:
            linear-gradient(to right, #18212b 0, #18212b var(--app-sidebar-width), transparent var(--app-sidebar-width)),
            #eef2f7;
    }
    html.nav-collapsed body.app-shell--with-sidebar {
        background:
            linear-gradient(to right, #18212b 0, #18212b var(--app-sidebar-rail-width), transparent var(--app-sidebar-rail-width)),
            #eef2f7;
    }
}
.app-sidebar.offcanvas-lg,
.app-shell--with-sidebar .app-main {
    transition: none !important;
}
`;

async function prepareSidebarForScreenshots(context) {
    await context.addInitScript((css) => {
        const add = () => {
            const style = document.createElement('style');
            style.setAttribute('data-screenshot-support', '');
            style.textContent = css;
            document.head.appendChild(style);
        };
        if (document.head) {
            add();
        } else {
            document.addEventListener('DOMContentLoaded', add);
        }
    }, SCREENSHOT_CSS);
}

module.exports = { prepareSidebarForScreenshots };
