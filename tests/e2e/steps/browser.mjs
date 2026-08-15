// Gemeinsame Browser-/Kontext-Optionen fuer Config, global-setup und Szenarien.
//
// Warum zentral: browser.newContext() erbt NICHT das `use` aus playwright.config.mjs.
// Ein manuell erzeugter Kontext bekaeme sonst Playwrights Default-Viewport (1280x720)
// und damit ein kleines Fenster, egal wie der Hauptkontext konfiguriert ist.

import { devices } from '@playwright/test';

export const BASE_URL = 'https://chormanager.ddev.site';

// Mobiler Lauf: E2E_VIEWPORT=mobile schaltet die GANZE Suite auf Telefonmasse um
// (npm run e2e:mobile). Kein eigenes Playwright-Project, damit Desktop und Mobile nie
// gleichzeitig dieselben Fixtures gegen dieselbe DB anlegen.
export const MOBILE = process.env.E2E_VIEWPORT === 'mobile';

// Zuschau-Modus: --headed oder --ui auf der Kommandozeile, alternativ E2E_WATCH=1.
// Die Erkennung laeuft im Hauptprozess (Config-Load). Test-Worker sind Kindprozesse und
// sehen `--headed` nicht in ihrer eigenen argv - deshalb wird die Entscheidung ueber die
// Umgebungsvariable an sie vererbt, damit Worker und Hauptprozess denselben Viewport nutzen.
export const WATCH_MODE = process.env.E2E_WATCH === '1'
    || process.argv.includes('--headed')
    || process.argv.includes('--ui');

if (WATCH_MODE) {
    process.env.E2E_WATCH = '1';
}

// Telefonmasse aus Playwrights Pixel-5-Profil. Die Breite (393px) liegt unter Bootstraps
// lg-Breakpoint, die Navigation klappt also wirklich in den Burger - genau das soll mobil
// getestet werden.
//
// BEWUSST OHNE `isMobile`/`deviceScaleFactor` aus dem Profil: mit isMobile=true meldet
// Chromium einen visuellen Viewport von 788x1458, waehrend das CSS-Layout 393px breit
// bleibt (verifiziert). Playwright scrollt Elemente dann an eine Position, die es fuer
// sichtbar haelt, das Element gilt nie als "stable" - jeder Klick auf einen Modal-Button
// lief in den Timeout. `hasTouch` liefert die Touch-Events auch ohne diese Emulation.
const MOBILE_DEVICE = devices['Pixel 5'];

// Zuschauen: viewport null = die Seite nutzt die echte Fenstergroesse. Nur so wirkt
// --start-maximized; ein fixer Viewport zwingt das Fenster zurueck auf seine Groesse.
// Mobil bleibt der Viewport auch beim Zuschauen fix - ein maximiertes Fenster waere
// schlicht kein Telefon mehr.
// Headless-Desktop: fixe Groesse, damit Layout und Screenshots deterministisch bleiben.
export const VIEWPORT = MOBILE
    ? MOBILE_DEVICE.viewport
    : (WATCH_MODE ? null : { width: 1600, height: 900 });

// Nur die Kontext-Optionen des Geraeteprofils uebernehmen: `devices[...]` enthaelt zusaetzlich
// `defaultBrowserType`, das browser.newContext() nicht kennt.
export const DEVICE_OPTIONS = MOBILE
    ? { viewport: VIEWPORT, hasTouch: MOBILE_DEVICE.hasTouch }
    : { viewport: VIEWPORT };

export const CONTEXT_OPTIONS = {
    baseURL: BASE_URL,
    ignoreHTTPSErrors: true,
    ...DEVICE_OPTIONS,
};

/**
 * Erzeugt einen Kontext mit denselben Optionen wie der Hauptkontext.
 * `overrides` ergaenzt oder ueberschreibt einzelne Optionen (z. B. storageState).
 */
export async function newBrowserContext(browser, overrides = {}) {
    return browser.newContext({ ...CONTEXT_OPTIONS, ...overrides });
}
