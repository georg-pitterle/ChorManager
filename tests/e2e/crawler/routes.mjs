import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const dir = path.dirname(fileURLToPath(import.meta.url));
const ROUTES_FILE = path.join(dir, '..', '..', '..', 'src', 'Routes.php');

// Extrahiert {name} und {name:regex} als Parameternamen.
function extractParams(pattern) {
    const params = [];
    const re = /\{([a-zA-Z_][a-zA-Z0-9_]*)(?::[^}]+)?\}/g;
    let m;
    while ((m = re.exec(pattern)) !== null) {
        params.push(m[1]);
    }
    return params;
}

// Haengt Gruppen-Praefix(e) und Routen-Pfad zusammen und normalisiert doppelte Slashes, ohne
// den fuehrenden "/" zu verlieren (z. B. Praefix "/song-library" + Pfad "" -> "/song-library",
// Praefix "" + Pfad "/voice-groups" -> "/voice-groups").
function joinPattern(prefix, routePath) {
    const combined = `${prefix}${routePath}`;
    const collapsed = combined.replace(/\/{2,}/g, '/');
    return collapsed === '' ? '/' : collapsed;
}

const GROUP_RE = /^->group\(\s*(?:'([^']*)'|"([^"]*)")/;
const ROUTE_RE = /^->(get|post|map)\(\s*(?:\[[^\]]*\]\s*,\s*)?(?:'([^']*)'|"([^"]*)")/;

// Parst Routes.php mit einem Klammertiefen-Stack, um verschachtelte
// ->group('/praefix', function () {...})-Aufrufe aufzuloesen. Slim erlaubt beliebig tiefe
// Gruppen-Verschachtelung (z. B. der aeussere Auth-Group mit leerem Praefix, darin
// $projGroup->group('/projects', ...), darin wieder Einzelrouten); die tatsaechliche URL einer
// Route ist die Konkatenation aller aktiven Praefixe plus ihres eigenen Pfads. Ohne diese
// Aufloesung wuerden gruppierte Routen (z. B. unter /projects, /tasks, /roles, /sponsoring,
// /song-library) mit falschem (ungeprefixtem) Pfad extrahiert, beim Crawlen 404en und effektiv
// nie besucht werden.
//
// Algorithmus: Der Datei-Text wird zeichenweise durchlaufen und dabei die Klammertiefe (Anzahl
// offener "{") mitgezaehlt - aber nur ausserhalb von String-Literalen und "//"-Kommentaren, da
// Routen-Patterns selbst geschweifte Klammern enthalten koennen (z. B. "{id:[0-9]+}"), die keine
// PHP-Blockstruktur sind. Bei einem "->group('praefix', ..." wird das Praefix gemerkt; sobald
// danach die oeffnende "{" der Closure gefunden wird, landet {Tiefe, Praefix} auf einem Stack.
// Faellt die Tiefe beim Schliessen einer "}" wieder auf genau die beim Push gemerkte Tiefe
// zurueck, ist das die schliessende Klammer dieser Gruppe - sie wird vom Stack genommen. Bei
// jedem "->get(/post(/map(" wird der volle Pfad als Konkatenation aller aktuell aktiven
// Praefixe (Stack, in Reihenfolge) plus dem eigenen Pfad gebildet.
export function getRoutes() {
    const src = fs.readFileSync(ROUTES_FILE, 'utf8');
    const routes = [];
    const groupStack = []; // { depth, prefix }
    let depth = 0;
    let pendingGroupPrefix = null; // gesetzt zwischen "->group('praefix'," und der oeffnenden "{"
    let inString = null; // aktuelles Anfuehrungszeichen ("'" oder '"') oder null

    let i = 0;
    while (i < src.length) {
        const ch = src[i];

        if (inString) {
            if (ch === '\\') {
                i += 2; // Escape-Sequenz ueberspringen (z. B. \' oder \\)
                continue;
            }
            if (ch === inString) {
                inString = null;
            }
            i += 1;
            continue;
        }

        if (ch === "'" || ch === '"') {
            inString = ch;
            i += 1;
            continue;
        }

        if (ch === '/' && src[i + 1] === '/') {
            const nl = src.indexOf('\n', i);
            i = nl === -1 ? src.length : nl + 1;
            continue;
        }

        if (src.startsWith('->group(', i)) {
            const m = GROUP_RE.exec(src.slice(i));
            if (m) {
                pendingGroupPrefix = m[1] !== undefined ? m[1] : m[2];
                i += m[0].length;
                continue;
            }
        }

        if (src.startsWith('->get(', i) || src.startsWith('->post(', i) || src.startsWith('->map(', i)) {
            const m = ROUTE_RE.exec(src.slice(i));
            if (m) {
                const method = m[1].toUpperCase() === 'POST' ? 'POST' : 'GET';
                const routePath = m[2] !== undefined ? m[2] : m[3];
                if (routePath === '' || routePath.startsWith('/')) {
                    const prefix = groupStack.map((g) => g.prefix).join('');
                    const pattern = joinPattern(prefix, routePath);
                    if (pattern.startsWith('/')) {
                        routes.push({ method, pattern, params: extractParams(pattern) });
                    }
                }
                i += m[0].length;
                continue;
            }
        }

        if (ch === '{') {
            if (pendingGroupPrefix !== null) {
                groupStack.push({ depth, prefix: pendingGroupPrefix });
                pendingGroupPrefix = null;
            }
            depth += 1;
            i += 1;
            continue;
        }

        if (ch === '}') {
            depth -= 1;
            if (groupStack.length > 0 && groupStack[groupStack.length - 1].depth === depth) {
                groupStack.pop();
            }
            i += 1;
            continue;
        }

        i += 1;
    }

    return routes;
}
