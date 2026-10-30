// Deterministische Daten für das Sponsoring-Szenario. Jeder Test bekommt eigene
// Namen, weil alle Tests eines Laufs dieselbe frische Datenbank teilen.

/** Datum als JJJJ-MM-TT, verschoben um `days` Tage ab heute. */
export function isoDate(days) {
    const date = new Date();
    date.setDate(date.getDate() + days);
    return date.toISOString().slice(0, 10);
}

/** Dasselbe Datum, wie es die Oberfläche zeigt: TT.MM.JJJJ. */
export function displayDate(iso) {
    const [year, month, day] = iso.split('-');
    return `${day}.${month}.${year}`;
}

export const CATEGORY_SPONSOR = {
    name: 'Bäckerei Größinger',
    similarName: 'bäckerei grössinger GmbH', // naming:ascii - bewusst andere Schreibweise
    exactSimilarName: 'Bäckerei-Größinger KG',
    newCategory: 'Konditoreien Wörthersee',
};

export const LOGO_FLOW = {
    project: { name: 'Frühlingskonzert Logos', description: 'Projekt mit Druckfrist', startDate: '', endDate: '' },
    package: {
        name: 'Paket Plakat',
        minAmount: '300',
        benefits: ['Logo auf dem Plakat'],
        deliverables: ['Beachflag übergeben'],
        logoPrint: true,
        logoOnline: false,
    },
    sponsor: { name: 'Autohaus Kärntner Straße', category: '' },
    amount: '350',
};

export const DEADLINE_FLOW = {
    project: { name: 'Herbstkonzert Fristen', description: 'Fristen erst später', startDate: '', endDate: '' },
    package: {
        name: 'Paket Online',
        minAmount: '100',
        benefits: ['Logo auf der Homepage'],
        deliverables: [],
        logoPrint: false,
        logoOnline: true,
    },
    sponsor: { name: 'Gärtnerei Fröhlich', category: '' },
    amount: '120',
};

export const ASSIGNEE_FLOW = {
    role: 'Sponsoring-Helfer',
    member: {
        firstName: 'Jürgen',
        lastName: 'Zuständig-Österle',
        email: 'juergen.zustaendig@example.test', // naming:ascii - E-Mail
        group: 'Tenor',
        sub: 'Tenor 1',
        role: 'Sponsoring-Helfer',
    },
    password: 'Sponsoring-Helfer-2026!',
    sponsor: { name: 'Sägewerk Hütter', category: '' },
    otherAttachment: 'mediadaten-saegewerk.pdf', // naming:ascii - Dateiname
    amount: '200',
};

export const RESPONSIBILITY_FLOW = {
    role: 'Sponsoring-Betreuung',
    member: {
        firstName: 'Agnes',
        lastName: 'Betreuer-Öhler',
        email: 'agnes.betreuer@example.test',
        group: 'Alt',
        sub: 'Alt 1',
        role: 'Sponsoring-Betreuung',
    },
    // Ohne Sponsoring-Recht: darf nicht zur Auswahl stehen.
    outsider: {
        firstName: 'Otto',
        lastName: 'Ohnerecht-Würth',
        email: 'otto.ohnerecht@example.test',
        group: 'Bass',
        sub: 'Bass 1',
    },
    sponsor: { name: 'Möbelhaus Wörgötter', category: '' },
    amount: '150',
};

export const TICK_FLOW = {
    role: 'Sponsoring-Material',
    member: {
        firstName: 'Mira',
        lastName: 'Material-Köhler',
        email: 'mira.material@example.test',
        group: 'Sopran',
        sub: 'Sopran 1',
        role: 'Sponsoring-Material',
    },
    password: 'Sponsoring-Material-2026!',
    package: {
        name: 'Paket Material',
        minAmount: '100',
        benefits: ['Logo an die Grafik übergeben'],
        deliverables: ['Logo-Datei geliefert'],
        logoPrint: false,
        logoOnline: false,
    },
    sponsor: { name: 'Tischlerei Mößner', category: '' },
    amount: '150',
};
