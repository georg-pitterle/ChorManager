import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import {
    AUDITOR,
    AUDITOR_PASSWORD,
    AUDITOR_ROLE,
    DEFAULT_ACCOUNTS,
    IMPORT_AMOUNTS,
    IMPORT_DESCRIPTIONS,
    OWN_IBAN,
    accountFor,
    bankStatementCsv,
} from '../data/finances.mjs';
import {
    canReverse,
    cancelImport,
    confirmImport,
    createAccount,
    createBooking,
    createRole,
    financeModuleEnabled,
    fiscalDay,
    openItemDescriptions,
    readAccount,
    readAccountStatement,
    readFiscalWindow,
    reverseBooking,
    runningNumberOf,
    setClosedUntil,
    submitAndWait,
    uploadStatement,
} from '../steps/finances.mjs';
import { createMember } from '../steps/members.mjs';
import { login } from '../steps/auth.mjs';
import { setMemberPassword } from '../steps/authz.mjs';

const BASE_URL = 'https://chormanager.ddev.site';

// Finanzverwaltung entlang der Aufgaben eines Vereinskassiers. Jeder Test legt sein
// eigenes Konto an, damit die Faelle sich nicht gegenseitig die Bestaende verschieben.
//
// Das Finanzmodul ist ueber FEATURE_FINANCE gegatet - ohne das Modul liefert
// /finances 404, dann wird uebersprungen statt faelschlich rot zu werden.
//
// Nacheinander statt parallel: Die Config setzt fullyParallel, wodurch auch die
// Tests INNERHALB dieser Datei gleichzeitig liefen. Alle teilen ueber den
// storageState dieselbe PHP-Session - und damit die einmaligen Flash-Meldungen
// ($_SESSION['success'|'error']) und den zwischengespeicherten Import
// ($_SESSION['finance_import']). Parallel konsumiert dann der eine Test die
// Meldung des anderen. "default" stellt die uebliche Reihenfolge je Datei wieder
// her, ohne nach einem Fehler die restlichen Faelle zu ueberspringen.
test.describe.configure({ mode: 'default' });

let fiscal;

test.beforeEach(async ({ page }) => {
    test.skip(!(await financeModuleEnabled(page)), 'FEATURE_FINANCE ist in dieser Umgebung aus.');
    fiscal = await readFiscalWindow(page);
});

// 1. Zahlungskreise: Produkt-Default vorhanden, eigenes Konto anlegbar.
test('Konten: Standardkonten vorhanden, eigenes Konto mit Anfangsbestand anlegbar', async ({ page }) => {
    await page.goto('/finances/accounts');
    for (const name of DEFAULT_ACCOUNTS) {
        await expect(page.getByText(name, { exact: true }).first()).toBeVisible();
    }

    const savings = accountFor(fiscal, 'savings');
    await createAccount(page, savings);

    const account = await readAccount(page, savings.name);
    expect(account.opening).toBe(savings.openingBalanceValue);
    // Ohne Buchungen ist der aktuelle Bestand genau der Anfangsbestand.
    expect(account.balance).toBe(savings.openingBalanceValue);
    expect(account.bookings).toBe(0);

    // IBAN wird normalisiert abgelegt (ohne Leerzeichen, Grossbuchstaben).
    await expect(page.getByText(savings.iban, { exact: false }).first()).toBeVisible();
});

// 2. Laufender Betrieb: Einnahme und Ausgabe buchen, Bestand fortschreiben.
test('Buchungen: Einnahme und Ausgabe verändern den Kontostand', async ({ page }) => {
    const cash = accountFor(fiscal, 'cash');
    await createAccount(page, cash);

    await createBooking(page, {
        invoiceDate: fiscalDay(fiscal, 5),
        paymentDate: fiscalDay(fiscal, 5),
        description: 'Kartenverkauf Adventkonzert',
        type: 'income',
        accountName: cash.name,
        amount: '340,00',
        group: 'Konzert E2E',
    });
    await createBooking(page, {
        invoiceDate: fiscalDay(fiscal, 6),
        paymentDate: fiscalDay(fiscal, 6),
        description: 'Verpflegung Helferteam',
        type: 'expense',
        accountName: cash.name,
        amount: '87,50',
    });

    const account = await readAccount(page, cash.name);
    expect(account.bookings).toBe(2);
    expect(account.balance).toBeCloseTo(cash.openingBalanceValue + 340 - 87.5, 2);

    // Laufende Nummern werden fortlaufend und aufsteigend vergeben.
    await page.goto('/finances');
    const first = await runningNumberOf(page, 'Kartenverkauf Adventkonzert');
    const second = await runningNumberOf(page, 'Verpflegung Helferteam');
    expect(second).toBeGreaterThan(first);
});

// 3. Zufluss-Abfluss-Prinzip: Rechnung ohne Zahldatum ist ein offener Posten.
test('Offene Posten: ohne Zahldatum kein Kassavorgang, nach Nachtragen im Jahr', async ({ page }) => {
    const account = accountFor(fiscal, 'openItems');
    await createAccount(page, account);

    await createBooking(page, {
        invoiceDate: fiscalDay(fiscal, 10),
        description: 'Notenlieferung Musikverlag',
        type: 'expense',
        accountName: account.name,
        amount: '199,00',
    });

    // (a) Steht unter "Offene Posten", nicht in der Buchungsliste des Jahres.
    expect(await openItemDescriptions(page)).toContain('Notenlieferung Musikverlag');
    await expect(
        page.locator('#financesTable tbody tr', { hasText: 'Notenlieferung Musikverlag' })
    ).toHaveCount(0);

    // (b) Ohne Zahlung bleibt der Bestand unberührt.
    let state = await readAccount(page, account.name);
    expect(state.balance).toBe(account.openingBalanceValue);

    // (c) Zahldatum nachtragen -> wandert ins Geschäftsjahr und mindert den Bestand.
    await page.goto('/finances');
    const section = page.locator('section[aria-labelledby="finances-open-title"]');
    await section.locator('button[data-bs-target="#financeModal"]').first().click();
    const modal = page.locator('#financeModal');
    await modal.waitFor({ state: 'visible' });
    await modal.locator('input[name="payment_date"]').fill(fiscalDay(fiscal, 12));
    await submitAndWait(page, modal.locator('button[type="submit"]'));

    expect(await openItemDescriptions(page)).not.toContain('Notenlieferung Musikverlag');
    state = await readAccount(page, account.name);
    expect(state.balance).toBeCloseTo(account.openingBalanceValue - 199, 2);
});

// 4. Kontoauszug-Import inkl. Dublettenschutz beim zweiten Einlesen.
test('Import: Kontoauszug übernehmen, zweiter Lauf erkennt alle Zeilen als Dublette', async ({ page }) => {
    const account = accountFor(fiscal, 'import');
    await createAccount(page, account);

    const csvPath = path.join(os.tmpdir(), `e2e-kontoauszug-${Date.now()}.csv`);
    fs.writeFileSync(csvPath, bankStatementCsv(fiscal), 'utf8');

    try {
        // (a) Vorschau: alle drei Zeilen lesbar, Konto über die eigene IBAN vorbelegt.
        const preview = await uploadStatement(page, csvPath);
        expect(preview.rows).toBe(3);
        expect(preview.importable).toBe(3);
        expect(preview.duplicates).toBe(0);
        expect(preview.suggestedAccount).toContain(account.name);
        await expect(page.getByText(OWN_IBAN, { exact: false }).first()).toBeVisible();

        // Gegenpartei kommt von der Seite, die nicht das eigene Konto ist - bei der
        // Lastschrift also vom Auftraggeber, obwohl der Betrag negativ ist.
        await expect(page.getByText(IMPORT_DESCRIPTIONS.hosting, { exact: false })).toBeVisible();

        await confirmImport(page);
        await expect(page.locator('.alert-success')).toContainText('3 Buchungen importiert');

        // (b) Buchungen sind da, Richtung stimmt, Bestand passt.
        const expected = account.openingBalanceValue
            + IMPORT_AMOUNTS.subsidy + IMPORT_AMOUNTS.rent + IMPORT_AMOUNTS.hosting;
        const state = await readAccount(page, account.name);
        expect(state.bookings).toBe(3);
        expect(state.balance).toBeCloseTo(expected, 2);

        // (c) Derselbe Auszug erneut: nichts ist mehr übernehmbar.
        const second = await uploadStatement(page, csvPath);
        expect(second.rows).toBe(3);
        expect(second.importable).toBe(0);
        expect(second.duplicates).toBe(3);
        await cancelImport(page);

        expect((await readAccount(page, account.name)).bookings).toBe(3);
    } finally {
        fs.rmSync(csvPath, { force: true });
    }
});

// 5. Korrektur per Storno statt Löschen (§ 131 BAO: Original bleibt nachvollziehbar).
test('Storno: Gegenbuchung neutralisiert den Bestand, Original bleibt stehen', async ({ page }) => {
    const account = accountFor(fiscal, 'reversal');
    await createAccount(page, account);

    await createBooking(page, {
        invoiceDate: fiscalDay(fiscal, 15),
        paymentDate: fiscalDay(fiscal, 15),
        description: 'Doppelt erfasste Saalmiete',
        type: 'expense',
        accountName: account.name,
        amount: '300,00',
    });

    await page.goto('/finances');
    const number = await runningNumberOf(page, 'Doppelt erfasste Saalmiete');
    expect((await readAccount(page, account.name)).balance)
        .toBeCloseTo(account.openingBalanceValue - 300, 2);

    await reverseBooking(page, number);

    // (a) Original bleibt sichtbar und ist als storniert gekennzeichnet.
    await page.goto('/finances');
    await expect(page.locator('#financesTable tbody tr', { hasText: 'Doppelt erfasste Saalmiete' }).first())
        .toContainText('storniert');

    // (b) Gegenbuchung verweist auf die Ursprungsnummer.
    await expect(page.getByText(`Storno zu Nr. ${number}`, { exact: false }).first()).toBeVisible();

    // (c) In Summe heben sich beide auf.
    expect((await readAccount(page, account.name)).balance).toBe(account.openingBalanceValue);

    // (d) Ein zweites Storno derselben Buchung bietet die UI nicht mehr an.
    expect(await canReverse(page, number)).toBe(false);
});

// 6. Jahresabschluss: geprüfter Zeitraum ist gegen Änderungen gesperrt.
test('Jahressperre: abgeschlossener Zeitraum lehnt Änderungen ab', async ({ page }) => {
    const account = accountFor(fiscal, 'lock');
    await createAccount(page, account);

    await createBooking(page, {
        invoiceDate: fiscalDay(fiscal, 2),
        paymentDate: fiscalDay(fiscal, 2),
        description: 'Mitgliedsbeitrag geprüftes Jahr',
        type: 'income',
        accountName: account.name,
        amount: '120,00',
    });

    await page.goto('/finances');
    const number = await runningNumberOf(page, 'Mitgliedsbeitrag geprüftes Jahr');

    try {
        await setClosedUntil(page, fiscalDay(fiscal, 3));

        // Betrag der gesperrten Buchung ändern -> serverseitig abgelehnt.
        await page.goto('/finances');
        const row = page.locator(`tr[data-sort-running_number="${number}"]`);
        await row.locator('[data-action="edit-finance"]').click();
        const modal = page.locator('#financeModal');
        await modal.waitFor({ state: 'visible' });
        await modal.locator('input[name="amount"]').fill('999,00');
        await submitAndWait(page, modal.locator('button[type="submit"]'));

        await expect(page.locator('.alert-danger')).toContainText('abgeschlossen');
        expect((await readAccount(page, account.name)).balance)
            .toBeCloseTo(account.openingBalanceValue + 120, 2);
    } finally {
        // Sperre wieder aufheben, damit spätere Tests/Crawler-Läufe nicht darüber stolpern.
        await setClosedUntil(page, '');
    }
});

// 7. Rohdaten für Rechnungsprüfer und Steuerberater.
test('Export: CSV enthält Kopfzeile und die Buchungen des Geschäftsjahres', async ({ page }) => {
    const account = accountFor(fiscal, 'export');
    await createAccount(page, account);
    await createBooking(page, {
        invoiceDate: fiscalDay(fiscal, 8),
        paymentDate: fiscalDay(fiscal, 8),
        description: 'Spende Jubiläumsfeier',
        type: 'income',
        accountName: account.name,
        amount: '75,00',
    });

    const response = await page.request.get('/finances/export');
    expect(response.status()).toBe(200);
    expect(response.headers()['content-type']).toContain('text/csv');
    expect(response.headers()['content-disposition']).toContain('attachment;');

    const csv = await response.text();
    // BOM, damit Excel die Umlaute korrekt liest.
    expect(csv.startsWith('﻿')).toBe(true);
    expect(csv).toContain('"Lfd. Nr.";Rechnungsdatum;Zahldatum;Beschreibung');
    expect(csv).toContain('Spende Jubiläumsfeier');
    expect(csv).toContain(account.name);
});

// 8. Kassabericht: die Zahlen, die in der Generalversammlung vorgelegt werden.
test('Kassabericht: Anfangsbestand plus Einnahmen minus Ausgaben ergibt den Endbestand', async ({ page }) => {
    const account = accountFor(fiscal, 'report');
    await createAccount(page, account);

    await createBooking(page, {
        invoiceDate: fiscalDay(fiscal, 3),
        paymentDate: fiscalDay(fiscal, 3),
        description: 'Förderung Land Bericht',
        type: 'income',
        accountName: account.name,
        amount: '600,00',
    });
    await createBooking(page, {
        invoiceDate: fiscalDay(fiscal, 4),
        paymentDate: fiscalDay(fiscal, 4),
        description: 'Dirigentenhonorar Bericht',
        type: 'expense',
        accountName: account.name,
        amount: '250,00',
    });
    // Offener Posten darf den Bericht nicht verfälschen.
    await createBooking(page, {
        invoiceDate: fiscalDay(fiscal, 4),
        description: 'Noch offene Rechnung Bericht',
        type: 'expense',
        accountName: account.name,
        amount: '5.000,00',
    });

    const statement = await readAccountStatement(page, account.name);
    expect(statement.opening).toBe(account.openingBalanceValue);
    expect(statement.income).toBe(600);
    expect(statement.expense).toBe(250);
    expect(statement.closing).toBeCloseTo(account.openingBalanceValue + 600 - 250, 2);
});

// 9. Rechteschnitt: Rechnungsprüfer darf lesen und exportieren, aber nichts ändern.
test('Berechtigung: Nur-Lese-Rolle sieht das Kassabuch, darf aber nicht buchen', async ({ page, browser }) => {
    await createRole(page, {
        name: AUDITOR_ROLE,
        level: 20,
        permissions: ['can_read_finances'],
    });
    await createMember(page, AUDITOR);
    setMemberPassword(AUDITOR.email, AUDITOR_PASSWORD);

    const context = await browser.newContext({ baseURL: BASE_URL, ignoreHTTPSErrors: true });
    // Frischer Kontext erbt den Admin-storageState aus der Config - Cookies leeren,
    // sonst leitet /login sofort aufs Dashboard des Admins.
    await context.clearCookies();
    const auditorPage = await context.newPage();

    try {
        await login(auditorPage, { email: AUDITOR.email, password: AUDITOR_PASSWORD });

        // (a) Lesen und Rohdatenexport sind erlaubt.
        expect((await auditorPage.request.get('/finances')).status()).toBe(200);
        expect((await auditorPage.request.get('/finances/report')).status()).toBe(200);
        expect((await auditorPage.request.get('/finances/export')).status()).toBe(200);
        expect((await auditorPage.request.get('/finances/journal')).status()).toBe(200);

        // (b) Schreibende Aktionen sind gesperrt.
        expect((await auditorPage.request.post('/finances/save')).status()).toBe(403);
        expect((await auditorPage.request.post('/finances/accounts/save')).status()).toBe(403);
        expect((await auditorPage.request.post('/finances/import/preview')).status()).toBe(403);

        // (c) Die UI bietet die Schaltflächen erst gar nicht an.
        await auditorPage.goto('/finances');
        await expect(auditorPage.locator('[data-bs-target="#financeModal"]')).toHaveCount(0);
        await expect(auditorPage.locator('[data-bs-target="#financeImportModal"]')).toHaveCount(0);
    } finally {
        await context.close();
    }
});
