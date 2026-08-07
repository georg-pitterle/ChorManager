import { test, expect } from '@playwright/test';
import { getRoutes } from './routes.mjs';
import { attachConsoleWatcher, checkResponse, checkHtmlForPhpErrors, collectInternalLinks } from './detectors.mjs';
import { isDenied } from './denylist.mjs';

// Ganzer Lauf besucht ~120+ URLs inkl. Klick-Phase; der Default-Test-Timeout aus
// playwright.config.mjs (120s) reicht dafuer nicht. Lokal (nur fuer diesen Test) erhoehen,
// statt den globalen Timeout fuer alle e2e-Tests zu verschieben.
const TEST_TIMEOUT_MS = 480_000;
// Bremst Seiten mit vielen Buttons: verhindert, dass ein einzelner Seitenaufruf durch sehr
// viele (stale) Klick-Versuche den Lauf dominiert.
const MAX_BUTTONS_PER_PAGE = 25;
const CLICK_TIMEOUT_MS = 1000;

// attachConsoleWatcher() filtert bewusst NICHTS (siehe dortiger Kommentar) - jede Ausnahme fuer
// einen 401/403-"Failed to load resource"-Konsolenfehler muss hier explizit, eng gefasst (exakte
// Seiten-URL + Statusmuster) und mit verifizierter Begruendung stehen. Alles andere bleibt ein
// Befund. Chromium liefert in msg.text() keine Ressourcen-URL, nur den Status - die Zuordnung
// erfolgt daher ueber die gerade gecrawlte Seiten-URL.
const KNOWN_BENIGN_CONSOLE_ERRORS = [
    {
        // NewsletterController::create() liefert bewusst HTTP 403 (bzw. dessen browsereigene
        // Konsolenmeldung), wenn getAccessibleProjects($userId) leer ist - also der eingeloggte
        // User Mitglied keines einzigen Projekts ist (src/Controllers/NewsletterController.php,
        // getAccessibleProjects(): "return $user->projects()->orderBy('name')->get();"). Der
        // E2E-Bootstrap-Admin (tests/e2e/global-setup.mjs -> /setup) legt ausser sich selbst
        // keine Projekte und keine Projektmitgliedschaften an. Die Rolle "Admin" hat
        // can_manage_newsletters=1 (src/Controllers/AuthController.php::processSetup) - es ist
        // also kein Rechte-/Autorisierungsproblem, sondern ein bewusster Leerzustands-Guard in
        // der Controller-Logik selbst, der in diesem minimalen Bootstrap-Fixture immer greift.
        pageUrl: '/newsletters/create',
        pattern: /Failed to load resource:.*status of 40[13]/,
    },
];

function isKnownBenignConsoleError(pageUrl, errorText) {
    return KNOWN_BENIGN_CONSOLE_ERRORS.some((entry) => entry.pageUrl === pageUrl && entry.pattern.test(errorText));
}

// Wie KNOWN_BENIGN_CONSOLE_ERRORS, aber fuer den Navigations-Kanal: manche Seiten liefern bewusst
// einen HTTP-Fehlerstatus (z. B. 403) als Top-Level-Navigation. Chromium rendert dafuer keine
// Seite, sondern wirft je nach Timing/Modus (headed vs. headless) page.goto mit
// net::ERR_HTTP_RESPONSE_CODE_FAILURE ab - statt eine Response mit Status 403 zurueckzugeben (die
// checkResponse ohnehin als 401/403 durchwinkt). Nur EXAKTE, verifiziert-gutartige URL+Fehlermuster
// eintragen; ein ERR_HTTP_RESPONSE_CODE_FAILURE auf einer NICHT gelisteten URL (z. B. echte 500)
// bleibt ein Befund.
const KNOWN_BENIGN_NAV_ERRORS = [
    {
        // Gleiche Ursache wie der /newsletters/create-Eintrag in KNOWN_BENIGN_CONSOLE_ERRORS:
        // NewsletterController::create() liefert HTTP 403, wenn der eingeloggte User (der minimale
        // Bootstrap-Admin) Mitglied keines Projekts ist. Als Top-Level-goto aeussert sich dieser
        // 403 im headed-Modus als ERR_HTTP_RESPONSE_CODE_FAILURE. Kein Autorisierungs-Bug, sondern
        // ein bewusster Leerzustands-Guard (siehe ausfuehrliche Begruendung oben).
        pageUrl: '/newsletters/create',
        pattern: /ERR_HTTP_RESPONSE_CODE_FAILURE/,
    },
];

function isKnownBenignNavError(pageUrl, errorText) {
    return KNOWN_BENIGN_NAV_ERRORS.some((entry) => entry.pageUrl === pageUrl && entry.pattern.test(errorText));
}

function stripQueryAndHash(href) {
    return href.split('#')[0].split('?')[0];
}

// Navigiert robust: ein Klick auf der vorigen Seite kann eine Navigation ausgeloest haben, die
// erst jetzt noch in der Schwebe ist - dann bricht Chromium das naechste page.goto mit
// "interrupted by another navigation" ab. Das ist ein Crawler-Timing-Artefakt (kein App-Fehler):
// einmal auf Ruhe warten und erneut navigieren. Tritt der Fehler danach erneut auf, wird er
// weitergereicht (echter Navigationsfehler).
async function gotoResilient(page, url) {
    let lastErr;
    for (let attempt = 0; attempt < 3; attempt += 1) {
        try {
            return await page.goto(url, { waitUntil: 'domcontentloaded' });
        } catch (e) {
            lastErr = e;
            if (/interrupted by another navigation/i.test(e.message)) {
                await page.waitForLoadState('domcontentloaded').catch(() => {});
                continue;
            }
            throw e;
        }
    }
    throw lastErr;
}

// Baut aus GET-Routen konkrete URLs. Parametrisierte Routen werden mit IDs
// gefuellt, die von den Listenseiten (ohne Parameter) gescraped werden.
//
// routes.mjs loest ->group('/praefix', ...)-Verschachtelung (auch mehrfach geschachtelt) selbst
// auf, die extrahierten Patterns sind also bereits vollstaendig praefixiert (z. B.
// "/song-library/{id:[0-9]+}" statt nur "/{id:[0-9]+}"). Zusaetzlich sammeln wir hier die
// tatsaechlich im Markup verlinkten Pfade (discoveredLinks) waehrend des Scrapens: Das deckt u.
// a. dynamische Links mit IDs ab, die nicht aus einer einzelnen Route-Praefix-Heuristik
// stammen, und dient als Bestaetigung fuer geratene Parameter-URLs (siehe 404-Politik weiter
// unten im Haupttest).
async function resolveConcreteUrls(page, routes) {
    const getRoutesOnly = routes.filter((r) => r.method === 'GET');
    const staticGets = getRoutesOnly.filter((r) => r.params.length === 0);
    const urls = new Set(staticGets.map((r) => r.pattern));

    // IDs und echte interne Links aus allen statischen Seiten sammeln (Links wie /projects/5/...).
    const idsByPrefix = new Map();
    const discoveredLinks = new Set();
    for (const url of staticGets.map((r) => r.pattern)) {
        const resp = await page.goto(url, { waitUntil: 'domcontentloaded' }).catch(() => null);
        if (!resp) {
            continue;
        }
        const links = await collectInternalLinks(page);
        for (const rawHref of links) {
            const href = stripQueryAndHash(rawHref);
            discoveredLinks.add(href);
            const m = href.match(/^(\/[a-zA-Z0-9_-]+)\/(\d+)/);
            if (m) {
                if (!idsByPrefix.has(m[1])) {
                    idsByPrefix.set(m[1], new Set());
                }
                idsByPrefix.get(m[1]).add(m[2]);
            }
        }
    }

    // Ein-Parameter-Routen mit gefundenen IDs konkretisieren. Die ID stammt aus einer
    // Praefix-Heuristik (erstes Pfadsegment der Route), nicht aus einer garantiert passenden
    // Quelle - deshalb werden diese URLs separat als paramSubstitutedUrls markiert (siehe
    // 404-Politik im Haupttest).
    const paramSubstitutedUrls = new Set();
    for (const r of getRoutesOnly.filter((x) => x.params.length === 1)) {
        const prefix = '/' + r.pattern.split('/')[1];
        const ids = idsByPrefix.get(prefix);
        if (!ids) {
            continue;
        }
        for (const id of ids) {
            const concreteUrl = r.pattern.replace(/\{[^}]+\}/, id);
            urls.add(concreteUrl);
            paramSubstitutedUrls.add(concreteUrl);
        }
    }

    // Tatsaechlich verlinkte Seiten ergaenzen (deckt zusaetzliche, im Markup gefundene Pfade ab,
    // die nicht 1:1 einer GET-Route mit hoechstens einem Parameter entsprechen).
    for (const href of discoveredLinks) {
        if (!isDenied('', href)) {
            urls.add(href);
        }
    }

    // Alle URLs, die aus routes.mjs (also aus Routes.php) stammen - sowohl parameterlose als
    // auch per Praefix-Heuristik parametrisierte. Diese Menge braucht der Haupttest fuer die
    // 404-Politik: Manche statische GET-Routen sind hinter einem Feature-Modul-Flag
    // (`if ($settings['modules'][...])` in Routes.php, z. B. /finances, /sponsoring, /budget,
    // /registrations, /newsletters) versteckt registriert. Diese Module sind per Default AUS
    // (nur per FEATURE_*-Env aktiviert); ist ein Modul aus, registriert Slim die Route gar nicht
    // und jeder Aufruf liefert 404 - unabhaengig vom Environment ist das kein Bug, sondern
    // erwartetes Verhalten. Ein 404 auf so einer route-derived URL darf den Lauf daher nicht rot
    // faerben.
    const routeDerivedUrls = new Set([...staticGets.map((r) => r.pattern), ...paramSubstitutedUrls]);

    return { urls: [...urls], discoveredLinks, paramSubstitutedUrls, routeDerivedUrls };
}

test('Crawler: alle erreichbaren Seiten ohne Fehler', async ({ page }) => {
    test.setTimeout(TEST_TIMEOUT_MS);

    const findings = [];
    const routeNotFoundWarnings = [];
    const consoleErrors = attachConsoleWatcher(page);
    const routes = getRoutes();
    const { urls, discoveredLinks, routeDerivedUrls } = await resolveConcreteUrls(page, routes);
    let buttonsClicked = 0;

    for (const url of urls) {
        consoleErrors.length = 0;
        const resp = await gotoResilient(page, url).catch((e) => {
            // Zwei gutartige, KEIN-Befund-Faelle:
            // 1) Download-Endpunkte (Backups, Anhaenge, ...) unterbrechen die Navigation absichtlich
            //    per Browser-Download statt eine Seite zu laden - Erfolgsfall dieser Routen.
            // 2) "interrupted by another navigation": rein clientseitiges Crawler-Timing-Artefakt -
            //    ein vorheriger Button-Klick (Formular-Submit / JS-Redirect) hat eine Navigation
            //    ausgeloest, die erst jetzt noch in der Schwebe ist und unser goto abbricht. Das ist
            //    per Definition KEIN App-Fehler (echte Defekte zeigen sich als 5xx/PHP/JS/Timeout,
            //    alle weiterhin abgedeckt). gotoResilient versucht es zuvor mehrfach; bleibt es dabei,
            //    ueberspringen wir diese URL, statt sie faelschlich als Befund zu werten.
            if (
                !/download is starting|interrupted by another navigation/i.test(e.message)
                && !isKnownBenignNavError(url, e.message)
            ) {
                findings.push(`${url} :: Navigationsfehler ${e.message}`);
            }
            return null;
        });
        if (!resp) {
            continue;
        }

        const httpErr = checkResponse(resp);
        // 404-Politik: Ein 404 auf einer URL, die aus der Routen-Tabelle stammt (routeDerivedUrls -
        // statisch/parameterlos ODER per Praefix-Heuristik parametrisiert, siehe
        // resolveConcreteUrls), ist KEIN Befund, sondern nur eine Warnung. Zwei Gruende:
        // 1) Manche statischen GET-Routen in Routes.php sind hinter einem Feature-Modul-Flag
        //    (`if ($settings['modules'][...])`) registriert (z. B. /finances, /sponsoring,
        //    /budget, /registrations, /newsletters). Diese Module sind per Default AUS - ist ein
        //    Modul aus, registriert Slim die Route gar nicht und JEDE Umgebung mit deaktiviertem
        //    Modul liefert 404, ohne dass etwas kaputt ist. Ein 404 hier faelschlich als Befund zu
        //    werten wuerde den Lauf in jeder Umgebung ohne alle Module rot faerben.
        // 2) Bei parametrisierten Routen kann die per Praefix-Heuristik geratene ID zu einer
        //    anderen, gleich benannten Prefix-Route gehoeren statt zu einer tatsaechlich
        //    existierenden Entitaet - ein reines Extraktions-Artefakt.
        // In beiden Faellen bleibt es ein echter Befund, wenn dieselbe URL zusaetzlich auch
        // tatsaechlich im Markup verlinkt wurde (discoveredLinks) - dann handelt es sich um einen
        // echten toten Link, unabhaengig davon, ob er auch in der Routen-Tabelle steht. Genuine
        // 5xx-Fehler sind IMMER ein Befund (siehe checkResponse), unabhaengig von der Herkunft der
        // URL.
        if (httpErr === 'HTTP 404' && routeDerivedUrls.has(url) && !discoveredLinks.has(url)) {
            routeNotFoundWarnings.push(url);
            continue;
        }
        if (httpErr) {
            findings.push(`${url} :: ${httpErr}`);
        }
        const html = await page.content();
        const phpErr = checkHtmlForPhpErrors(html);
        if (phpErr) {
            findings.push(`${url} :: PHP-Fehler "${phpErr}"`);
        }

        // Aggressive Klicks: alle sichtbaren, nicht-denylisteten Buttons. Ein Klick, der
        // navigiert (echter Link statt Modal-Button), darf die restlichen Buttons dieser Seite
        // nicht verhindern - sonst werden spaetere Buttons nie geklickt. Deshalb: nach einer
        // Navigation zurueck zu `url` und mit dem naechsten Index weitermachen. Die Locators
        // werden dafuer bei jeder Iteration frisch ermittelt (nach einer Navigation ist die alte
        // Liste stale); `clickIndex` wird NICHT zurueckgesetzt, damit derselbe navigierende
        // Button (typischerweise wieder an derselben Position) nicht erneut angeklickt wird und
        // die Schleife garantiert Fortschritt macht (durch `pageButtonCount` zusaetzlich auf
        // MAX_BUTTONS_PER_PAGE begrenzt, terminiert sie also in jedem Fall).
        const startUrl = page.url();
        let clickIndex = 0;
        let pageButtonCount = 0;
        while (pageButtonCount < MAX_BUTTONS_PER_PAGE) {
            // Nur echte <button>-Elemente aggressiv klicken (Modals oeffnen, JS-Handler/
            // Formular-Aktionen ausloesen). Navigations-Links (a.btn) werden bewusst NICHT geklickt:
            // Ihre Ziele sind bereits Teil der Routen-Crawl (jede GET-Seite wird ohnehin besucht),
            // ihr Anklicken navigiert nur den Tab weg und erzeugt "interrupted by another
            // navigation"-Races ohne zusaetzliche Fehlerabdeckung.
            const buttons = await page.locator('button:visible').all();
            if (clickIndex >= buttons.length) {
                break; // alle aktuell vorhandenen Targets abgearbeitet
            }
            const btn = buttons[clickIndex];
            clickIndex += 1;

            const text = (await btn.textContent().catch(() => '')) || '';
            const href = (await btn.getAttribute('href').catch(() => '')) || '';
            if (isDenied(text.trim(), href)) {
                continue;
            }

            await btn.click({ timeout: CLICK_TIMEOUT_MS }).catch(() => {});
            buttonsClicked += 1;
            pageButtonCount += 1;
            // Falls der Klick navigiert hat, auf Abschluss der Navigation warten, damit (a) die
            // url-Erkennung unten zuverlaessig ist und (b) keine Navigation in der Schwebe bleibt,
            // die sonst das naechste page.goto mit "interrupted by another navigation" abbricht.
            // waitForLoadState kehrt sofort zurueck, wenn keine Navigation lief (reiner Modal-Klick).
            await page.waitForLoadState('domcontentloaded', { timeout: 3000 }).catch(() => {});
            // Modal wieder schliessen, um Folgeklicks nicht zu blockieren.
            await page.keyboard.press('Escape').catch(() => {});

            if (page.url() !== startUrl) {
                // Klick hat navigiert: Konsolenfehler, die dabei auftraten, gehoeren der
                // Zielseite - nicht `url` - als Befund. Danach zurueck zu `url` navigieren und
                // die Konsolen-Sammlung fuer beide Uebergaenge zuruecksetzen, damit weder die
                // Zielseite ihre Fehler doppelt meldet noch Rauschen von der Rueckkehr-Navigation
                // faelschlich `url` zugeschrieben wird.
                const targetUrl = page.url();
                const targetPath = new URL(targetUrl).pathname;
                const targetConsoleErrors = consoleErrors.filter((e) => !isKnownBenignConsoleError(targetPath, e));
                if (targetConsoleErrors.length > 0) {
                    findings.push(`${targetUrl} (erreicht per Klick von ${url}) :: JS ${targetConsoleErrors.join(' | ')}`);
                }
                consoleErrors.length = 0;
                await gotoResilient(page, url).catch(() => {});
                consoleErrors.length = 0;
            }
        }
        if (pageButtonCount >= MAX_BUTTONS_PER_PAGE) {
            console.log(
                `CRAWLER-WARNUNG: MAX_BUTTONS_PER_PAGE (${MAX_BUTTONS_PER_PAGE}) erreicht auf ${url} `
                + '- moeglicherweise nicht alle Buttons dieser Seite geklickt.'
            );
        }

        const remainingConsoleErrors = consoleErrors.filter((e) => !isKnownBenignConsoleError(url, e));
        if (remainingConsoleErrors.length > 0) {
            findings.push(`${url} :: JS ${remainingConsoleErrors.join(' | ')}`);
        }
    }

    console.log(`CRAWLER-STATS: ${urls.length} URLs besucht, ${buttonsClicked} Buttons geklickt.`);
    if (routeNotFoundWarnings.length > 0) {
        console.log(
            'CRAWLER-WARNUNG: uebersprungen: nicht erreichbar (evtl. deaktiviertes Modul/Methode/Param):\n'
            + routeNotFoundWarnings.join('\n')
        );
    }
    if (findings.length > 0) {
        console.log('CRAWLER-BEFUNDE:\n' + findings.join('\n'));
    }
    expect(findings, findings.join('\n')).toHaveLength(0);
});
