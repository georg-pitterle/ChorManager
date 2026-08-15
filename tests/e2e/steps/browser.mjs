// Gemeinsame Browser-/Kontext-Optionen fuer Config, global-setup und Szenarien.
//
// Warum zentral: browser.newContext() erbt NICHT das `use` aus playwright.config.mjs.
// Ein manuell erzeugter Kontext bekaeme sonst Playwrights Default-Viewport (1280x720)
// und damit ein kleines Fenster, egal wie der Hauptkontext konfiguriert ist.

export const BASE_URL = 'https://chormanager.ddev.site';

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

// Zuschauen: viewport null = die Seite nutzt die echte Fenstergroesse. Nur so wirkt
// --start-maximized; ein fixer Viewport zwingt das Fenster zurueck auf seine Groesse.
// Headless: fixe Groesse, damit Layout und Screenshots deterministisch bleiben.
export const VIEWPORT = WATCH_MODE ? null : { width: 1600, height: 900 };

export const CONTEXT_OPTIONS = {
    baseURL: BASE_URL,
    ignoreHTTPSErrors: true,
    viewport: VIEWPORT,
};

/**
 * Erzeugt einen Kontext mit denselben Optionen wie der Hauptkontext.
 * `overrides` ergaenzt oder ueberschreibt einzelne Optionen (z. B. storageState).
 */
export async function newBrowserContext(browser, overrides = {}) {
    return browser.newContext({ ...CONTEXT_OPTIONS, ...overrides });
}
