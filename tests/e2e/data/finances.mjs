// Deterministische Testdaten fuer die Finanzverwaltung.
//
// Datumsangaben sind bewusst nicht hartkodiert: Der Beginn des Geschaeftsjahres
// steckt im Setting fiscal_year_start (die Migration seedet 01.10.) und ist vom
// Kassier aenderbar. Das Szenario liest das aktuelle Fenster deshalb zur Laufzeit
// aus der Kassabuch-Kopfzeile (steps/finances.mjs: readFiscalWindow) und legt alle
// Buchungen relativ zu dessen Beginn an - sonst laegen sie ausserhalb des Jahres,
// das die Liste zeigt, und das Szenario waere je nach Konfiguration rot.

import { fiscalDay, fiscalDayGerman } from '../steps/finances.mjs';

// Die Migration CreateFinanceAccounts legt diese beiden Konten als Produkt-Default
// an - das Szenario verifiziert sie, es legt sie nicht an (gleiche Logik wie bei
// den geseedeten SATB-Stimmgruppen im Bootstrap-Szenario).
export const DEFAULT_ACCOUNTS = ['Barkassa', 'Bankkonto'];

// Eigene IBAN des Vereins; steht in jeder Zeile des Beispielauszugs und dient dem
// Import zur Erkennung des Kontos.
export const OWN_IBAN = 'AT911600000100629615';

/**
 * Kontovorlagen ohne Stichtag - den setzt das Szenario aus dem gelesenen
 * Geschaeftsjahr (`accountFor`).
 */
export const ACCOUNTS = {
    savings: {
        name: 'Sparbuch Jubiläum',
        type: 'bank',
        iban: 'AT022050303300443714',
        openingBalance: '1.250,50',
        openingBalanceValue: 1250.5,
        sortOrder: 3,
    },
    cash: {
        name: 'Barkassa Konzertkasse',
        type: 'cash',
        iban: '',
        openingBalance: '200,00',
        openingBalanceValue: 200,
        sortOrder: 4,
    },
    openItems: {
        name: 'Konto Offene Posten',
        type: 'bank',
        iban: '',
        openingBalance: '1.250,50',
        openingBalanceValue: 1250.5,
        sortOrder: 5,
    },
    import: {
        name: 'Girokonto Verein',
        type: 'bank',
        iban: OWN_IBAN,
        openingBalance: '3.000,00',
        openingBalanceValue: 3000,
        sortOrder: 6,
    },
    reversal: {
        name: 'Konto Storno',
        type: 'bank',
        iban: '',
        openingBalance: '500,00',
        openingBalanceValue: 500,
        sortOrder: 7,
    },
    lock: {
        name: 'Konto Sperre',
        type: 'bank',
        iban: '',
        openingBalance: '500,00',
        openingBalanceValue: 500,
        sortOrder: 8,
    },
    export: {
        name: 'Konto Export',
        type: 'bank',
        iban: '',
        openingBalance: '800,00',
        openingBalanceValue: 800,
        sortOrder: 9,
    },
    report: {
        name: 'Konto Bericht',
        type: 'bank',
        iban: '',
        openingBalance: '800,00',
        openingBalanceValue: 800,
        sortOrder: 10,
    },
};

/** Kontovorlage mit Stichtag zum Beginn des laufenden Geschaeftsjahres. */
export function accountFor(fiscalWindow, key) {
    return { ...ACCOUNTS[key], openingDate: fiscalDay(fiscalWindow, 0) };
}

/**
 * Beispiel-Kontoauszug im Format der Bank (UTF-8 mit BOM, Semikolon, TT.MM.JJJJ,
 * Dezimalkomma), inhaltlich wie tests/Fixtures/bank_statement_sample.csv.
 *
 * Die dritte Zeile ist eine Lastschrift: negativer Betrag, aber der Auftraggeber
 * ist die Gegenpartei und der Empfaenger das eigene Konto. Damit prueft der Import
 * auch, dass die Gegenpartei ueber die eigene IBAN und nicht ueber das Vorzeichen
 * bestimmt wird.
 */
export function bankStatementCsv(fiscalWindow) {
    const day = (offset) => fiscalDayGerman(fiscalWindow, offset);
    const header = 'Buchungsdatum;Valutadatum;Betrag;Währung;Auftraggebername;'
        + 'Auftraggeber IBAN/Kto.Nr.;Auftraggeber BIC/BLZ;Empfängername;'
        + 'Empfänger IBAN/Kto.Nr.;Empfänger BIC/BLZ;Text;Verwendungszweck';

    const rows = [
        `${day(20)};${day(20)};250,00;EUR;Marktgemeinde Kuchl;`
            + `AT330100000000123456;BKAUATWW;Chorkuma;${OWN_IBAN};BTVAAT22XXX;`
            + 'SEPA-Überweisung;Förderung Chorjahr',
        `${day(21)};${day(21)};-480,75;EUR;Chorkuma;${OWN_IBAN};`
            + 'BTVAAT22XXX;Tiroler Landestheater;AT425700030055434325;HYPTAT22XXX;'
            + 'SEPA-Überweisung;Saalmiete Frühjahrskonzert',
        `${day(22)};${day(22)};-12,30;EUR;Hetzner Online GmbH;`
            + `DE44701600000000142108;GENODEFF701;Chorkuma;${OWN_IBAN};BTVAAT22XXX;`
            + 'SEPA-Lastschrift;Webhosting Vereinsseite',
    ];

    return `﻿${header}\n${rows.join('\n')}\n`;
}

/** Betraege der drei Importzeilen, vorzeichenrichtig aus Sicht des eigenen Kontos. */
export const IMPORT_AMOUNTS = { subsidy: 250, rent: -480.75, hosting: -12.3 };

/** Erwartete Beschreibungen der drei Importzeilen (Gegenpartei + Verwendungszweck). */
export const IMPORT_DESCRIPTIONS = {
    subsidy: 'Marktgemeinde Kuchl - Förderung Chorjahr',
    rent: 'Tiroler Landestheater - Saalmiete Frühjahrskonzert',
    hosting: 'Hetzner Online GmbH - Webhosting Vereinsseite',
};

// Mitglied, das nur lesen darf. Eine reine Lese-Rolle gibt es produktseitig nicht,
// das Szenario legt sie daher selbst an.
export const AUDITOR_ROLE = 'Rechnungsprüfung E2E';
export const AUDITOR = {
    firstName: 'Rita',
    lastName: 'Prüfer',
    email: 'rita.pruefer@chor.local',
    role: AUDITOR_ROLE,
    group: 'Alt',
    sub: 'Alt 1',
};
export const AUDITOR_PASSWORD = 'PrueferPass1234!';
