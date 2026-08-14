import { execFileSync } from 'node:child_process';
import { resolveBash } from './shell.mjs';

// Der Newsletter-Versand stellt Mails nur in die Warteschlange (mail_queue). Zugestellt werden
// sie vom Worker bin/process_mail_queue.php. In der Dev-Umgebung laeuft PHP im sendmail-Modus
// (SMTP_HOST ist leer), und DDEV leitet sendmail an Mailpit weiter - dort lassen sich die
// erzeugten Mails ueber die HTTP-API nachweisen.

const MAILPIT_BASE = 'https://chormanager.ddev.site:8026';

function ddevPhp(php) {
    return execFileSync(resolveBash(), ['-lc', `ddev php -r '${php}'`], { encoding: 'utf8' });
}

/**
 * Anzahl der noch nicht zugestellten Warteschlangen-Eintraege zu diesem Betreff.
 * Die Tabelle mail_queue kennt keine Newsletter-Spalte (die Zuordnung liegt in payload_json),
 * der Betreff ist dank der Lauf-Kennung im Titel aber eindeutig.
 */
export function countQueuedMails(subject) {
    const escaped = String(subject).replace(/"/g, '\\"');
    const php = `$pdo=new PDO("mysql:host=db;dbname=db","db","db");`
        + `$s=$pdo->prepare("SELECT COUNT(*) FROM mail_queue WHERE subject=? AND status=\\"queued\\"");`
        + `$s->execute(["${escaped}"]);echo (int) $s->fetchColumn();`;
    return Number(ddevPhp(php).trim());
}

/**
 * Arbeitet die Warteschlange ab, bis fuer den Newsletter nichts mehr offen ist.
 * Der Worker verarbeitet je Lauf eine begrenzte Menge, deshalb mehrere Durchgaenge.
 */
export function deliverQueuedMails(subject, maxRuns = 5) {
    for (let run = 0; run < maxRuns; run++) {
        if (countQueuedMails(subject) === 0) {
            return;
        }

        execFileSync(resolveBash(), ['-lc', 'ddev php bin/process_mail_queue.php'], { encoding: 'utf8' });
    }

    const remaining = countQueuedMails(subject);
    if (remaining > 0) {
        throw new Error(
            `Nach ${maxRuns} Durchgaengen liegen noch ${remaining} Mails mit dem Betreff `
            + `"${subject}" in der Warteschlange.`
        );
    }
}

/**
 * Empfaengeradressen aller Mails, die in Mailpit unter diesem Betreff liegen.
 *
 * @param {import('@playwright/test').APIRequestContext} request
 * @param {string} subject
 * @returns {Promise<string[]>}
 */
export async function mailpitRecipientsForSubject(request, subject) {
    const query = encodeURIComponent(`subject:"${subject}"`);
    const response = await request.get(`${MAILPIT_BASE}/api/v1/search?query=${query}&limit=200`);
    if (!response.ok()) {
        throw new Error(`Mailpit-Suche fehlgeschlagen: HTTP ${response.status()}`);
    }

    const payload = await response.json();
    return (payload.messages ?? []).flatMap((message) => (message.To ?? []).map((to) => to.Address));
}

/**
 * Liefert den Textinhalt der ersten Mail mit diesem Betreff (fuer Inhaltspruefungen).
 */
export async function mailpitBodyForSubject(request, subject) {
    const query = encodeURIComponent(`subject:"${subject}"`);
    const listResponse = await request.get(`${MAILPIT_BASE}/api/v1/search?query=${query}&limit=1`);
    const list = await listResponse.json();
    const first = (list.messages ?? [])[0];
    if (!first) {
        return '';
    }

    const messageResponse = await request.get(`${MAILPIT_BASE}/api/v1/message/${first.ID}`);
    const message = await messageResponse.json();
    return `${message.HTML ?? ''}\n${message.Text ?? ''}`;
}
