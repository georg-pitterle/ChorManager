// Deterministische Daten für das Newsletter-Szenario.
//
// Die E2E-DB ist bei jedem Lauf frisch, aber alle Szenarien teilen sie und laufen parallel.
// Deshalb:
//  - eigenes E-Mail-Präfix "nl." für jede Person, damit nichts mit anderen Szenarien kollidiert
//  - Empfängerzahlen werden im Szenario nur dort exakt geprüft, wo die Quelle allein aus
//    eigenen Daten besteht (eigenes Projekt, einzeln gewählte Personen). Die Quelle "Rolle"
//    umfasst auch Personen anderer Szenarien und wird deshalb nicht auf eine feste Zahl geprüft.

// Die Newsletter-Titel tragen eine Lauf-Kennung: Die Datenbank ist bei jedem Lauf frisch, der
// Mailpit-Posteingang aber nicht. Ohne die Kennung würden Mails früherer Läufe unter demselben
// Betreff gefunden und die Zustellprüfung verfälschen.
const RUN = Math.random().toString(36).slice(2, 8);

// Beide Redakteure brauchen das Recht "Newsletter verwalten". Laut Seed der Initial-Migration
// haben die Rollen "Vorstand" und "Chorleitung" can_manage_newsletters = 1; das Szenario
// verifiziert das zur Laufzeit gegen die DB, statt sich darauf zu verlassen.
export const EDITOR_PRIMARY = {
    firstName: 'Ruth',
    lastName: 'Schreiberin',
    email: 'nl.ruth.schreiberin@chor.local',
    role: 'Vorstand',
    group: 'Sopran',
    sub: 'Sopran 1',
};

export const EDITOR_SECOND = {
    firstName: 'Tobias',
    lastName: 'Mitschreiber',
    email: 'nl.tobias.mitschreiber@chor.local',
    role: 'Chorleitung',
    group: 'Tenor',
    sub: 'Tenor 1',
};

// Empfänger. PROJECT_MEMBERS landen im Testprojekt, OUTSIDER bleibt bewusst draußen und
// dient als Gegenprobe: er darf über die Projektquelle NICHT erreicht werden.
export const PROJECT_MEMBERS = [
    { firstName: 'Miriam', lastName: 'Fröhlich', email: 'nl.miriam.froehlich@chor.local', group: 'Alt', sub: 'Alt 1' },
    { firstName: 'Susanne', lastName: 'Käufer', email: 'nl.susanne.kaeufer@chor.local', group: 'Alt', sub: 'Alt 2' },
    { firstName: 'Bernd', lastName: 'Größing', email: 'nl.bernd.groessing@chor.local', group: 'Bass', sub: 'Bass 1' },
];

export const OUTSIDER = {
    firstName: 'Ottilie',
    lastName: 'Draußen',
    email: 'nl.ottilie.draussen@chor.local',
    group: 'Sopran',
    sub: 'Sopran 2',
};

export const NEWSLETTER_PASSWORD = 'NewsletterPass1234!';

export const NEWSLETTER_PROJECT = {
    name: 'Newsletter-Testprojekt Herbstkonzert',
    description: 'Projekt für das Newsletter-E2E-Szenario.',
    startDate: '2026-09-01',
    endDate: '2026-11-30',
};

// Vorlage, die im zweiten Newsletter geladen wird. Der Text muss eindeutig sein, damit das
// Szenario belegen kann, dass der Vorlageninhalt wirklich im Editor gelandet ist.
export const TEMPLATE = {
    name: 'E2E-Vorlage Konzertankündigung',
    description: 'Vorlage für das Newsletter-E2E-Szenario.',
    marker: 'Vorlagentext für die Konzertankündigung',
};

export const NEWSLETTER_WITHOUT_TEMPLATE = {
    title: `E2E Rundschreiben an das Projekt ${RUN}`,
    marker: 'Frei getippter Inhalt ohne Vorlage.',
};

export const NEWSLETTER_WITH_TEMPLATE = {
    title: `E2E Rundschreiben aus Vorlage ${RUN}`,
};

export const NEWSLETTER_COMBINED = {
    title: `E2E Rundschreiben an mehrere Quellen ${RUN}`,
    marker: 'Inhalt für die kombinierte Empfängerauswahl.',
};

export const NEWSLETTER_WITHOUT_RECIPIENTS = {
    title: `E2E Entwurf ohne Empfänger ${RUN}`,
    marker: 'Dieser Entwurf hat bewusst keine Empfängerquelle.',
};

export const NEWSLETTER_LOCKED = {
    title: `E2E Entwurf für den Sperrtest ${RUN}`,
    marker: 'Wird von zwei Personen gleichzeitig geöffnet.',
};

// --- Nachbericht an Veranstaltungsteilnehmer -------------------------------------------------
// Praxisfall: Nach einem Auftritt geht ein Dankeschön nur an die, die tatsächlich da waren.

export const CONCERT_EVENT = {
    title: 'E2E Herbstkonzert für den Nachbericht',
    date: '2026-10-24',
    startTime: '19:00',
    endTime: '21:30',
    type: 'Auftritt',
};

export const CONCERT_EDITOR = {
    firstName: 'Regine',
    lastName: 'Nachbericht',
    email: 'nl.regine.nachbericht@chor.local',
    role: 'Vorstand',
    group: 'Alt',
    sub: 'Alt 1',
};

// Zwei Anwesende, eine Entschuldigte: Die Quelle "Veranstaltungsteilnehmer" darf nur die
// beiden Anwesenden erfassen.
export const CONCERT_PRESENT = [
    { firstName: 'Hanna', lastName: 'Sängerin', email: 'nl.hanna.saengerin@chor.local', group: 'Sopran', sub: 'Sopran 1' },
    { firstName: 'Ulf', lastName: 'Bühnenreif', email: 'nl.ulf.buehnenreif@chor.local', group: 'Bass', sub: 'Bass 2' },
];

export const CONCERT_EXCUSED = {
    firstName: 'Elias',
    lastName: 'Krankgemeldet',
    email: 'nl.elias.krankgemeldet@chor.local',
    group: 'Tenor',
    sub: 'Tenor 2',
};

export const NEWSLETTER_EVENT_REPORT = {
    title: `E2E Nachbericht zum Herbstkonzert ${RUN}`,
    marker: 'Danke an alle, die auf der Bühne standen.',
};

// --- Bewährtes Rundschreiben als Vorlage sichern ----------------------------------------------
// Praxisfall: Ein gelungener Newsletter wird zur Vorlage und beim nächsten Mal wiederverwendet.

export const REUSE_EDITOR = {
    firstName: 'Vera',
    lastName: 'Wiederverwenderin',
    email: 'nl.vera.wiederverwenderin@chor.local',
    role: 'Chorleitung',
    group: 'Sopran',
    sub: 'Sopran 2',
};

export const REUSE_RECIPIENT = {
    firstName: 'Nora',
    lastName: 'Leserin',
    email: 'nl.nora.leserin@chor.local',
    group: 'Alt',
    sub: 'Alt 2',
};

export const NEWSLETTER_TO_REUSE = {
    title: `E2E Monatsrundschreiben Original ${RUN}`,
    marker: 'Aufbau, der sich bewährt hat: Begrüßung, Termine, Abschluss.',
};

export const SAVED_TEMPLATE = {
    name: 'E2E-Vorlage aus dem Monatsrundschreiben',
    description: 'Aus einem bestehenden Newsletter gesichert.',
};

export const NEWSLETTER_REUSING_TEMPLATE = {
    title: `E2E Monatsrundschreiben Folgeausgabe ${RUN}`,
};

// --- Empfängerkreis wächst zwischen Speichern und Versand -------------------------------------
// Praxisfall: Der Entwurf liegt ein paar Tage; in der Zwischenzeit tritt jemand dem Projekt bei.
// Beim Versand muss die Person mitkommen, weil die Empfänger frisch aufgelöst werden.

export const LATE_PROJECT = {
    name: 'Newsletter-Testprojekt Nachzügler',
    description: 'Projekt für die späte Zuordnung im Newsletter-E2E-Szenario.',
    startDate: '2026-09-01',
    endDate: '2026-12-20',
};

export const LATE_EDITOR = {
    firstName: 'Doris',
    lastName: 'Vorbereitend',
    email: 'nl.doris.vorbereitend@chor.local',
    role: 'Vorstand',
    group: 'Alt',
    sub: 'Alt 2',
};

export const LATE_EARLY_MEMBER = {
    firstName: 'Frieda',
    lastName: 'Frühdabei',
    email: 'nl.frieda.fruehdabei@chor.local',
    group: 'Sopran',
    sub: 'Sopran 1',
};

export const LATE_JOINER = {
    firstName: 'Naomi',
    lastName: 'Spätdazu',
    email: 'nl.naomi.spaetdazu@chor.local',
    group: 'Tenor',
    sub: 'Tenor 1',
};

export const NEWSLETTER_LATE_JOINER = {
    title: `E2E Rundschreiben mit später Zuordnung ${RUN}`,
    marker: 'Der Empfängerkreis wächst nach dem Speichern.',
};
