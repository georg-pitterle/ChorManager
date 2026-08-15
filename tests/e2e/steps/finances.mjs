// Bausteine für die Finanzverwaltung.
//
// Verifizierte Selektoren:
//  - templates/finances/index.twig
//      Buchungs-Modal #financeModal, Formular POST /finances/save
//      Felder: invoice_date, payment_date, description, group_name (Textfeld, per
//      #group_select auf "__new__" sichtbar), type, finance_account_id, amount
//      Konfigurations-Modal #settingsModal, Formular POST /finances/settings,
//      Felder fiscal_year_start + closed_until
//      Import-Modal #financeImportModal, Formular POST /finances/import/preview,
//      Datei-Feld name="statement"
//      Buchungszeilen: tr[data-sort-running_number], Zellen mit data-label
//      Storno: form[action$="/reverse"] im Aktionen-Dropdown der Zeile
//  - templates/finances/accounts.twig
//      Konto-Modal #financeAccountModal, Formular POST /finances/accounts/save
//      Felder: name, type, iban, opening_balance, opening_date, sort_order, is_active
//  - templates/finances/import.twig
//      Vorschau-Formular POST /finances/import/confirm, Kontoauswahl
//      finance_account_id, Zeilenauswahl selected[], Gruppe group[<index>]
//  - templates/finances/report.twig
//      Kassabericht je Konto in der ersten Tabelle unter "Kassabericht je Konto"
//  - templates/roles/index.twig
//      Rollen-Modal #addRoleModal, Felder name, hierarchy_level, can_*-Checkboxen

/** "1.234,56 €" -> 1234.56 (auch mit Minus und ohne Währungszeichen). */
export function parseMoney(text) {
    // Der Kassabericht setzt vor Ausgaben das typografische Minuszeichen U+2212,
    // nicht den ASCII-Bindestrich - ohne Normalisierung liefert parseFloat NaN.
    const cleaned = String(text)
        .replace(/−/g, '-')
        .replace(/\s| |€|\+/g, '')
        .replace(/\./g, '')
        .replace(',', '.');

    return Number.parseFloat(cleaned);
}

/**
 * Ist das Finanzmodul in dieser Umgebung überhaupt registriert?
 * Ohne FEATURE_FINANCE=true liefert /finances 404 - das Szenario wird dann
 * übersprungen statt fälschlich rot zu werden.
 */
export async function financeModuleEnabled(page) {
    return (await page.request.get('/finances')).status() !== 404;
}

/**
 * Das aktuell im Kassabuch geöffnete Geschäftsjahr, aus der Kopfzeile gelesen
 * ("Geschäftsjahr: 01.10.2025 – 30.09.2026").
 *
 * Bewusst nicht nachgerechnet: Der Beginn steckt im Setting fiscal_year_start
 * (Migration seedet 01.10.), und der Kassier kann ihn jederzeit ändern. Ein im
 * Test hartkodiertes Fenster wäre ab der nächsten Aenderung falsch - und die
 * Buchungen lägen dann unbemerkt außerhalb des geprüften Jahres.
 */
export async function readFiscalWindow(page) {
    await page.goto('/finances');
    const label = await page.locator('.page-header p strong').first().innerText();
    const [start, end] = label.split('–').map((part) => part.trim());

    return { start: parseGermanDate(start), end: parseGermanDate(end) };
}

/** "01.10.2025" -> Date (UTC, damit die Tagesarithmetik zeitzonenfrei bleibt). */
export function parseGermanDate(text) {
    const [day, month, year] = text.trim().split('.').map(Number);

    return new Date(Date.UTC(year, month - 1, day));
}

/** Tag innerhalb des Geschäftsjahres als ISO-Datum (Formularformat). */
export function fiscalDay(fiscalWindow, offsetDays) {
    const date = new Date(fiscalWindow.start.getTime());
    date.setUTCDate(date.getUTCDate() + offsetDays);

    return date.toISOString().slice(0, 10);
}

/** Derselbe Tag im deutschen Format, wie ihn Bank-Exporte liefern (TT.MM.JJJJ). */
export function fiscalDayGerman(fiscalWindow, offsetDays) {
    const [year, month, day] = fiscalDay(fiscalWindow, offsetDays).split('-');

    return `${day}.${month}.${year}`;
}

/**
 * Formular abschicken und die daraus folgende Navigation abwarten.
 *
 * Bewusst weder `waitForURL('**\/finances**')` noch `waitForLoadState`: Das
 * URL-Muster passt auch auf die Ausgangsseite und kehrt sofort zurück, und der
 * Ladezustand gilt zu dem Zeitpunkt noch für das ALTE Dokument. Beides lässt die
 * anschließende Prüfung gegen die Seite vor dem POST laufen - Flash-Meldungen
 * fehlen dann scheinbar. Das load-Event feuert dagegen genau für das Dokument
 * nach dem Redirect, auch wenn Quell- und Zielpfad identisch sind.
 */
export async function submitAndWait(page, submitLocator) {
    const navigated = page.waitForEvent('load', { timeout: 30_000 });
    await submitLocator.click();
    await navigated;
}

export async function createAccount(page, account) {
    await page.goto('/finances/accounts');
    await page.click('.page-actions [data-bs-target="#financeAccountModal"]');
    const modal = page.locator('#financeAccountModal');
    await modal.waitFor({ state: 'visible' });

    await modal.locator('input[name="name"]').fill(account.name);
    await modal.locator('select[name="type"]').selectOption(account.type);
    await modal.locator('input[name="iban"]').fill(account.iban ?? '');
    await modal.locator('input[name="opening_balance"]').fill(account.openingBalance);
    await modal.locator('input[name="opening_date"]').fill(account.openingDate);
    await modal.locator('input[name="sort_order"]').fill(String(account.sortOrder ?? 0));

    await submitAndWait(page, modal.locator('button[type="submit"]'));
}

/** Zeile der Kontenliste als Zahlen: Anfangsbestand, aktueller Bestand, Buchungsanzahl. */
export async function readAccount(page, name) {
    await page.goto('/finances/accounts');
    const row = page.locator('tr', { has: page.getByText(name, { exact: true }) }).first();
    await row.waitFor({ state: 'visible' });

    return {
        opening: parseMoney(await row.locator('td[data-label="Anfangsbestand"]').innerText()),
        balance: parseMoney(await row.locator('td[data-label="Aktueller Bestand"]').innerText()),
        bookings: Number.parseInt(await row.locator('td[data-label="Buchungen"]').innerText(), 10),
    };
}

/**
 * Buchung über das Kassabuch-Modal erfassen.
 * booking: { invoiceDate, paymentDate?, description, type, accountName, amount, group? }
 * Ohne paymentDate entsteht ein offener Posten.
 */
export async function createBooking(page, booking) {
    await page.goto('/finances');
    await page.click('.page-actions [data-bs-target="#financeModal"]');
    const modal = page.locator('#financeModal');
    await modal.waitFor({ state: 'visible' });

    await modal.locator('input[name="invoice_date"]').fill(booking.invoiceDate);
    if (booking.paymentDate) {
        await modal.locator('input[name="payment_date"]').fill(booking.paymentDate);
    }
    await modal.locator('input[name="description"]').fill(booking.description);
    await modal.locator('select[name="type"]').selectOption(booking.type);
    // Die Option trägt "<Name> (Bank|Bar)" samt Umbruch/Einrückung aus dem Template;
    // selectOption akzeptiert nur exakte Labels, daher über den Text die value lesen.
    const accountSelect = modal.locator('select[name="finance_account_id"]');
    const accountValue = await accountSelect
        .locator('option', { hasText: booking.accountName })
        .first()
        .getAttribute('value');
    await accountSelect.selectOption(accountValue);
    await modal.locator('input[name="amount"]').fill(booking.amount);

    if (booking.group) {
        // Das Textfeld wird erst sichtbar, wenn im Select "+ Neue Gruppe eingeben…"
        // gewählt ist (handleGroupSelect in public/js/finances.js).
        await modal.locator('#group_select').selectOption('__new__');
        await modal.locator('#group_name').fill(booking.group);
    }

    await submitAndWait(page, modal.locator('button[type="submit"]'));
}

/** Zeile der Buchungsliste anhand ihrer laufenden Nummer. */
export function bookingRow(page, runningNumber) {
    return page.locator(`tr[data-sort-running_number="${runningNumber}"]`);
}

/** Laufende Nummer der Buchung mit dieser Beschreibung (aus der Buchungsliste). */
export async function runningNumberOf(page, description) {
    const row = page.locator('#financesTable tbody tr', { hasText: description }).first();
    await row.waitFor({ state: 'visible' });

    return Number.parseInt(await row.locator('td[data-label="Lfd. Nr."]').innerText(), 10);
}

/** Beschreibungen der offenen Posten (Buchungen ohne Zahldatum). */
export async function openItemDescriptions(page) {
    await page.goto('/finances');
    const section = page.locator('section[aria-labelledby="finances-open-title"]');
    if (await section.count() === 0) {
        return [];
    }

    return (await section.locator('td[data-label="Beschreibung"]').allInnerTexts())
        .map((text) => text.trim());
}

/**
 * Buchung stornieren. Das Aktionen-Dropdown muss vorher geöffnet werden, und das
 * Formular hängt an einem data-confirm (natives confirm() aus public/js/common.js) -
 * Playwright würde den Dialog sonst automatisch abweisen und der POST bliebe aus.
 */
export async function reverseBooking(page, runningNumber) {
    await page.goto('/finances');
    const row = bookingRow(page, runningNumber);
    await row.waitFor({ state: 'visible' });

    await row.locator('button.dropdown-toggle-split').click();
    const form = row.locator('form[action$="/reverse"]');
    await form.waitFor({ state: 'visible' });

    page.once('dialog', (dialog) => dialog.accept());
    await submitAndWait(page, form.locator('button[type="submit"]'));
}

/** Ist für diese Zeile überhaupt noch ein Storno möglich? */
export async function canReverse(page, runningNumber) {
    await page.goto('/finances');
    const row = bookingRow(page, runningNumber);
    await row.waitFor({ state: 'visible' });
    await row.locator('button.dropdown-toggle-split').click();

    return (await row.locator('form[action$="/reverse"]').count()) > 0;
}

/** Buchungen bis zu diesem Tag abschließen; leerer String hebt die Sperre auf. */
export async function setClosedUntil(page, isoDate) {
    await page.goto('/finances');
    await page.click('.page-actions [data-bs-target="#settingsModal"]');
    const modal = page.locator('#settingsModal');
    await modal.waitFor({ state: 'visible' });

    await modal.locator('input[name="closed_until"]').fill(isoDate);
    await submitAndWait(page, modal.locator('button[type="submit"]'));
}

/**
 * Kontoauszug hochladen. Liefert die Vorschau-Seite zurück, ohne zu übernehmen.
 * Rückgabe: { rows, importable, duplicates, suggestedAccount }
 */
export async function uploadStatement(page, filePath) {
    await page.goto('/finances');
    await page.click('.page-actions [data-bs-target="#financeImportModal"]');
    const modal = page.locator('#financeImportModal');
    await modal.waitFor({ state: 'visible' });

    await modal.locator('input[name="statement"]').setInputFiles(filePath);
    await submitAndWait(page, modal.locator('button[type="submit"]'));
    await page.waitForURL('**/finances/import/preview');

    const table = page.locator('.finance-import-table');
    await table.waitFor({ state: 'visible' });

    const rows = await table.locator('tbody tr').count();
    const importable = await table.locator('input[name="selected[]"]:not([disabled])').count();
    const duplicates = await page.getByText('bereits importiert').count();
    const suggestedAccount = await page.locator('select[name="finance_account_id"]')
        .locator('option:checked')
        .innerText();

    return { rows, importable, duplicates, suggestedAccount: suggestedAccount.trim() };
}

/** Vorschau bestätigen (übernimmt die angehakten Zeilen). */
export async function confirmImport(page) {
    await submitAndWait(page, page.locator('#finance-import-submit'));
}

/** Vorschau verwerfen. */
export async function cancelImport(page) {
    await submitAndWait(page, page.locator('form[action="/finances/import/cancel"] button[type="submit"]'));
}

/** Kassabericht der Auswertung: je Konto Anfangsbestand, Einnahmen, Ausgaben, Endbestand. */
export async function readAccountStatement(page, accountName) {
    await page.goto('/finances/report');
    const row = page.locator('.finance-statement-table tbody tr', { hasText: accountName }).first();
    await row.waitFor({ state: 'visible' });

    // Einnahmen/Ausgaben sind Spaltensummen und werden mit führendem +/- nur zur
    // besseren Lesbarkeit angezeigt - als Betrag ohne Vorzeichen zurückgeben.
    // Anfangs- und Endbestand behalten ihr Vorzeichen, ein Konto kann im Minus sein.
    return {
        opening: parseMoney(await row.locator('td[data-label="Anfangsbestand"]').innerText()),
        income: Math.abs(parseMoney(await row.locator('td[data-label="Einnahmen"]').innerText())),
        expense: Math.abs(parseMoney(await row.locator('td[data-label="Ausgaben"]').innerText())),
        closing: parseMoney(await row.locator('td[data-label="Endbestand"]').innerText()),
    };
}

/** Rolle mit genau den übergebenen Rechten anlegen (Rechte = Namen der can_*-Felder). */
export async function createRole(page, { name, level = 10, permissions = [] }) {
    await page.goto('/roles');
    await page.click('[data-bs-target="#addRoleModal"]');
    const modal = page.locator('#addRoleModal');
    await modal.waitFor({ state: 'visible' });

    await modal.locator('input[name="name"]').fill(name);
    await modal.locator('input[name="hierarchy_level"]').fill(String(level));
    for (const permission of permissions) {
        await modal.locator(`input[name="${permission}"]`).check();
    }

    await submitAndWait(page, modal.locator('button[type="submit"]'));
}
