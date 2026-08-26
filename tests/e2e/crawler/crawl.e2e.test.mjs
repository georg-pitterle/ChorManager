import { test, expect } from '@playwright/test';
import { getRoutes } from './routes.mjs';
import { attachConsoleWatcher, checkResponse, checkHtmlForPhpErrors, collectInternalLinks } from './detectors.mjs';
import { isDenied } from './denylist.mjs';

// Ganzer Lauf besucht ~120+ URLs inkl. Klick-Phase; der Default-Test-Timeout aus
// playwright.config.mjs (120s) reicht dafür nicht. Lokal (nur für diesen Test) erhöhen,
// statt den globalen Timeout für alle e2e-Tests zu verschieben.
const TEST_TIMEOUT_MS = 480_000;
// Bremst Seiten mit vielen Buttons: verhindert, dass ein einzelner Seitenaufruf durch sehr
// viele (stale) Klick-Versuche den Lauf dominiert.
const MAX_BUTTONS_PER_PAGE = 60;
// Listenseiten (/users, /voice-groups, /finances ...) wiederholen dieselben Zeilen-Buttons je
// Datensatz. Ohne Begrenzung je Button-ART verbraucht eine lange Liste das gesamte Budget mit
// Duplikaten - die restlichen, fachlich anderen Buttons derselben Seite werden dann nie geklickt.
// Zwei Klicks je Art reichen: der erste deckt den Handler ab, der zweite einen Folgezustand.
const MAX_CLICKS_PER_BUTTON_KIND = 2;
const CLICK_TIMEOUT_MS = 1000;

// Fasst Buttons zusammen, die sich nur durch ihren Datensatz unterscheiden: Ziffern (Modal-IDs
// wie #deleteModal17, formaction="/users/delete/17") werden auf "#" normalisiert.
function buttonKind({ text, target, type, name, formaction, className }) {
    return [text, target, type, name, formaction, className]
        .map((part) => (part || '').replace(/\s+/g, ' ').trim().toLowerCase().replace(/\d+/g, '#'))
        .join('|');
}

// attachConsoleWatcher() filtert bewusst NICHTS (siehe dortiger Kommentar) - jede Ausnahme für
// einen 401/403-"Failed to load resource"-Konsolenfehler muss hier explizit, eng gefasst (exakte
// Seiten-URL + Statusmuster) und mit verifizierter Begründung stehen. Alles andere bleibt ein
// Befund. Chromium liefert in msg.text() keine Ressourcen-URL, nur den Status - die Zuordnung
// erfolgt daher über die gerade gecrawlte Seiten-URL.
const KNOWN_BENIGN_CONSOLE_ERRORS = [
    {
        // NewsletterController::create() liefert bewusst HTTP 403 (bzw. dessen browsereigene
        // Konsolenmeldung), wenn getAccessibleProjects($userId) leer ist - also der eingeloggte
        // User Mitglied keines einzigen Projekts ist (src/Controllers/NewsletterController.php,
        // getAccessibleProjects(): "return $user->projects()->orderBy('name')->get();"). Der
        // E2E-Bootstrap-Admin (tests/e2e/global-setup.mjs -> /setup) legt außer sich selbst
        // keine Projekte und keine Projektmitgliedschaften an. Die Rolle "Admin" hat
        // can_manage_newsletters=1 (src/Controllers/AuthController.php::processSetup) - es ist
        // also kein Rechte-/Autorisierungsproblem, sondern ein bewusster Leerzustands-Guard in
        // der Controller-Logik selbst, der in diesem minimalen Bootstrap-Fixture immer greift.
        pageUrl: '/newsletters/create',
        pattern: /Failed to load resource:.*status of 40[13]/,
    },
];

// Anders als die seitenscharfen Einträge oben ist diese Meldung auf JEDER Seite gutartig, weil
// sie nicht aus der Anwendung stammt: Chromium meldet jede geblockte Skriptausführung in einem
// sandboxed iframe als console.error, und ausgelöst wird sie hier von Playwright selbst. Der
// Trace-Snapshotter (trace: 'retain-on-failure' in playwright.config.mjs) hängt sein
// Aufzeichnungsskript in JEDEN Rahmen; in einem <iframe sandbox=""> ohne 'allow-scripts' scheitert
// das zwangsläufig. Verifiziert: Mit tracing.start({ snapshots: true }) erscheint die Meldung schon
// bei einem leeren sandboxed iframe, ohne Tracing nie.
//
// Die Sandbox ist Absicht - templates/newsletters/edit.twig, templates/newsletters/preview.twig und
// templates/admin/mail_queue/show.twig zeigen fremdes Mail-HTML und dürfen daraus kein Skript
// ausführen lassen. Greift die Sperre einmal bei echtem Newsletter-Inhalt statt beim
// Playwright-Skript, ist auch das der Erfolgsfall und kein Befund.
const SANDBOXED_FRAME_SCRIPT_BLOCK = /Blocked script execution in .* because the document's frame is sandboxed/;

function isKnownBenignConsoleError(pageUrl, errorText) {
    if (SANDBOXED_FRAME_SCRIPT_BLOCK.test(errorText)) {
        return true;
    }

    return KNOWN_BENIGN_CONSOLE_ERRORS.some((entry) => entry.pageUrl === pageUrl && entry.pattern.test(errorText));
}

// Wie KNOWN_BENIGN_CONSOLE_ERRORS, aber für den Navigations-Kanal: manche Seiten liefern bewusst
// einen HTTP-Fehlerstatus (z. B. 403) als Top-Level-Navigation. Chromium rendert dafür keine
// Seite, sondern wirft je nach Timing/Modus (headed vs. headless) page.goto mit
// net::ERR_HTTP_RESPONSE_CODE_FAILURE ab - statt eine Response mit Status 403 zurückzugeben (die
// checkResponse ohnehin als 401/403 durchwinkt). Nur EXAKTE, verifiziert-gutartige URL+Fehlermuster
// eintragen; ein ERR_HTTP_RESPONSE_CODE_FAILURE auf einer NICHT gelisteten URL (z. B. echte 500)
// bleibt ein Befund.
const KNOWN_BENIGN_NAV_ERRORS = [
    {
        // Gleiche Ursache wie der /newsletters/create-Eintrag in KNOWN_BENIGN_CONSOLE_ERRORS:
        // NewsletterController::create() liefert HTTP 403, wenn der eingeloggte User (der minimale
        // Bootstrap-Admin) Mitglied keines Projekts ist. Als Top-Level-goto äußert sich dieser
        // 403 im headed-Modus als ERR_HTTP_RESPONSE_CODE_FAILURE. Kein Autorisierungs-Bug, sondern
        // ein bewusster Leerzustands-Guard (siehe ausführliche Begründung oben).
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

// Navigiert robust: ein Klick auf der vorigen Seite kann eine Navigation ausgelöst haben, die
// erst jetzt noch in der Schwebe ist - dann bricht Chromium das nächste page.goto mit
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
// gefüllt, die von den Listenseiten (ohne Parameter) gescraped werden.
//
// routes.mjs löst ->group('/präfix', ...)-Verschachtelung (auch mehrfach geschachtelt) selbst
// auf, die extrahierten Patterns sind also bereits vollständig präfixiert (z. B.
// "/song-library/{id:[0-9]+}" statt nur "/{id:[0-9]+}"). Zusätzlich sammeln wir hier die
// tatsächlich im Markup verlinkten Pfade (discoveredLinks) während des Scrapens: Das deckt u.
// a. dynamische Links mit IDs ab, die nicht aus einer einzelnen Route-Präfix-Heuristik
// stammen, und dient als Bestätigung für geratene Parameter-URLs (siehe 404-Politik weiter
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
    // Präfix-Heuristik (erstes Pfadsegment der Route), nicht aus einer garantiert passenden
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

    // Tatsächlich verlinkte Seiten ergänzen (deckt zusätzliche, im Markup gefundene Pfade ab,
    // die nicht 1:1 einer GET-Route mit höchstens einem Parameter entsprechen).
    for (const href of discoveredLinks) {
        if (!isDenied('', href)) {
            urls.add(href);
        }
    }

    // Alle URLs, die aus routes.mjs (also aus Routes.php) stammen - sowohl parameterlose als
    // auch per Präfix-Heuristik parametrisierte. Diese Menge braucht der Haupttest für die
    // 404-Politik: Manche statische GET-Routen sind hinter einem Feature-Modul-Flag
    // (`if ($settings['modules'][...])` in Routes.php, z. B. /finances, /sponsoring, /budget,
    // /registrations, /newsletters) versteckt registriert. Diese Module sind per Default AUS
    // (nur per FEATURE_*-Env aktiviert); ist ein Modul aus, registriert Slim die Route gar nicht
    // und jeder Aufruf liefert 404 - unabhängig vom Environment ist das kein Bug, sondern
    // erwartetes Verhalten. Ein 404 auf so einer route-derived URL darf den Lauf daher nicht rot
    // färben.
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
    let duplicatesSkipped = 0;

    for (const url of urls) {
        consoleErrors.length = 0;
        const resp = await gotoResilient(page, url).catch((e) => {
            // Zwei gutartige, KEIN-Befund-Fälle:
            // 1) Download-Endpunkte (Backups, Anhänge, ...) unterbrechen die Navigation absichtlich
            //    per Browser-Download statt eine Seite zu laden - Erfolgsfall dieser Routen.
            // 2) "interrupted by another navigation": rein clientseitiges Crawler-Timing-Artefakt -
            //    ein vorheriger Button-Klick (Formular-Submit / JS-Redirect) hat eine Navigation
            //    ausgelöst, die erst jetzt noch in der Schwebe ist und unser goto abbricht. Das ist
            //    per Definition KEIN App-Fehler (echte Defekte zeigen sich als 5xx/PHP/JS/Timeout,
            //    alle weiterhin abgedeckt). gotoResilient versucht es zuvor mehrfach; bleibt es dabei,
            //    überspringen wir diese URL, statt sie fälschlich als Befund zu werten.
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
        // statisch/parameterlos ODER per Präfix-Heuristik parametrisiert, siehe
        // resolveConcreteUrls), ist KEIN Befund, sondern nur eine Warnung. Zwei Gründe:
        // 1) Manche statischen GET-Routen in Routes.php sind hinter einem Feature-Modul-Flag
        //    (`if ($settings['modules'][...])`) registriert (z. B. /finances, /sponsoring,
        //    /budget, /registrations, /newsletters). Diese Module sind per Default AUS - ist ein
        //    Modul aus, registriert Slim die Route gar nicht und JEDE Umgebung mit deaktiviertem
        //    Modul liefert 404, ohne dass etwas kaputt ist. Ein 404 hier fälschlich als Befund zu
        //    werten würde den Lauf in jeder Umgebung ohne alle Module rot färben.
        // 2) Bei parametrisierten Routen kann die per Präfix-Heuristik geratene ID zu einer
        //    anderen, gleich benannten Prefix-Route gehören statt zu einer tatsächlich
        //    existierenden Entität - ein reines Extraktions-Artefakt.
        // In beiden Fällen bleibt es ein echter Befund, wenn dieselbe URL zusätzlich auch
        // tatsächlich im Markup verlinkt wurde (discoveredLinks) - dann handelt es sich um einen
        // echten toten Link, unabhängig davon, ob er auch in der Routen-Tabelle steht. Genuine
        // 5xx-Fehler sind IMMER ein Befund (siehe checkResponse), unabhängig von der Herkunft der
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
        // nicht verhindern - sonst werden spätere Buttons nie geklickt. Deshalb: nach einer
        // Navigation zurück zu `url` und mit dem nächsten Index weitermachen. Die Locators
        // werden dafür bei jeder Iteration frisch ermittelt (nach einer Navigation ist die alte
        // Liste stale); `clickIndex` wird NICHT zurückgesetzt, damit derselbe navigierende
        // Button (typischerweise wieder an derselben Position) nicht erneut angeklickt wird und
        // die Schleife garantiert Fortschritt macht (durch `pageButtonCount` zusätzlich auf
        // MAX_BUTTONS_PER_PAGE begrenzt, terminiert sie also in jedem Fall).
        const startUrl = page.url();
        let clickIndex = 0;
        let pageButtonCount = 0;
        // Klicks je Button-Art dieser Seite (siehe MAX_CLICKS_PER_BUTTON_KIND).
        const clicksPerKind = new Map();
        while (pageButtonCount < MAX_BUTTONS_PER_PAGE) {
            // Nur echte <button>-Elemente aggressiv klicken (Modals öffnen, JS-Handler/
            // Formular-Aktionen auslösen). Navigations-Links (a.btn) werden bewusst NICHT geklickt:
            // Ihre Ziele sind bereits Teil der Routen-Crawl (jede GET-Seite wird ohnehin besucht),
            // ihr Anklicken navigiert nur den Tab weg und erzeugt "interrupted by another
            // navigation"-Races ohne zusätzliche Fehlerabdeckung.
            const buttons = await page.locator('button:visible').all();
            if (clickIndex >= buttons.length) {
                break; // alle aktuell vorhandenen Targets abgearbeitet
            }
            const btn = buttons[clickIndex];
            clickIndex += 1;

            // Alle Attribute in EINEM Roundtrip lesen; ein stale gewordener Button liefert null
            // und wird übersprungen.
            const attrs = await btn.evaluate((el) => ({
                text: el.textContent || '',
                href: el.getAttribute('href') || '',
                target: el.getAttribute('data-bs-target') || '',
                type: el.getAttribute('type') || '',
                name: el.getAttribute('name') || '',
                formaction: el.getAttribute('formaction') || '',
                className: el.getAttribute('class') || '',
            })).catch(() => null);
            if (attrs === null) {
                continue;
            }
            if (isDenied(attrs.text.trim(), attrs.href)) {
                continue;
            }

            const kind = buttonKind(attrs);
            const kindClicks = clicksPerKind.get(kind) ?? 0;
            if (kindClicks >= MAX_CLICKS_PER_BUTTON_KIND) {
                duplicatesSkipped += 1;
                continue;
            }
            clicksPerKind.set(kind, kindClicks + 1);

            await btn.click({ timeout: CLICK_TIMEOUT_MS }).catch(() => {});
            buttonsClicked += 1;
            pageButtonCount += 1;
            // Falls der Klick navigiert hat, auf Abschluss der Navigation warten, damit (a) die
            // url-Erkennung unten zuverlässig ist und (b) keine Navigation in der Schwebe bleibt,
            // die sonst das nächste page.goto mit "interrupted by another navigation" abbricht.
            // waitForLoadState kehrt sofort zurück, wenn keine Navigation lief (reiner Modal-Klick).
            await page.waitForLoadState('domcontentloaded', { timeout: 3000 }).catch(() => {});
            // Modal wieder schließen, um Folgeklicks nicht zu blockieren.
            await page.keyboard.press('Escape').catch(() => {});

            if (page.url() !== startUrl) {
                // Klick hat navigiert: Konsolenfehler, die dabei auftraten, gehören der
                // Zielseite - nicht `url` - als Befund. Danach zurück zu `url` navigieren und
                // die Konsolen-Sammlung für beide Übergänge zurücksetzen, damit weder die
                // Zielseite ihre Fehler doppelt meldet noch Rauschen von der Rückkehr-Navigation
                // fälschlich `url` zugeschrieben wird.
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
                + `- ${clicksPerKind.size} Button-Arten geklickt, möglicherweise nicht alle.`
            );
        }

        const remainingConsoleErrors = consoleErrors.filter((e) => !isKnownBenignConsoleError(url, e));
        if (remainingConsoleErrors.length > 0) {
            findings.push(`${url} :: JS ${remainingConsoleErrors.join(' | ')}`);
        }
    }

    console.log(
        `CRAWLER-STATS: ${urls.length} URLs besucht, ${buttonsClicked} Buttons geklickt, `
        + `${duplicatesSkipped} Wiederholungen derselben Button-Art übersprungen.`
    );
    if (routeNotFoundWarnings.length > 0) {
        console.log(
            'CRAWLER-WARNUNG: übersprungen: nicht erreichbar (evtl. deaktiviertes Modul/Methode/Param):\n'
            + routeNotFoundWarnings.join('\n')
        );
    }
    if (findings.length > 0) {
        console.log('CRAWLER-BEFUNDE:\n' + findings.join('\n'));
    }
    expect(findings, findings.join('\n')).toHaveLength(0);
});
