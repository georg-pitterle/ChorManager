import { expect } from '@playwright/test';

// Legt den ersten Admin über /setup an (nur möglich, wenn keine User existieren).
// Verifizierte Felder aus templates/auth/setup.twig: first_name, last_name, email, password.
// AuthController::processSetup loggt den neuen Admin sofort ein und leitet nach /dashboard
// weiter (nicht /login) - verifiziert in src/Controllers/AuthController.php.
export async function setupAdmin(page, admin) {
    await page.goto('/setup');
    await page.fill('input[name="first_name"]', admin.firstName);
    await page.fill('input[name="last_name"]', admin.lastName);
    await page.fill('input[name="email"]', admin.email);
    await page.fill('input[name="password"]', admin.password);
    await page.click('form[action="/setup"] button[type="submit"]');
    await page.waitForURL('**/dashboard');
}

// Verifizierte Felder aus templates/auth/login.twig: email, password, Checkbox #rememberMe
// ("Eingeloggt bleiben", name="remember").
export async function login(page, { email, password, remember = false }) {
    await page.goto('/login');
    await page.fill('input[name="email"]', email);
    await page.fill('input[name="password"]', password);
    if (remember) {
        await page.check('#rememberMe');
    }
    await page.click('form[action="/login"] button[type="submit"]');
    await expect(page).not.toHaveURL(/\/login(\?|$)/);
}

/**
 * Spielt nach, was eine offene Seite erlebt, deren Sitzung auf dem Server verfallen ist -
 * etwa weil die Webapp länger im Hintergrund lag: Das Sitzungs-Cookie wird verworfen, das
 * Remember-Me-Cookie (remember_login) bleibt. Die nächste Anfrage der Seite trifft auf keine
 * Sitzung mehr.
 *
 * Liefert den Token, den die Seite vorher trug, damit das Szenario belegen kann, dass er sich
 * beim Neuaufbau wirklich geändert hat.
 */
export async function loseSessionKeepingRememberMe(page) {
    const context = page.context();
    const cookies = await context.cookies();
    expect(
        cookies.some((cookie) => cookie.name === 'remember_login'),
        'Für den stillen Neuaufbau muss "Eingeloggt bleiben" gewählt sein'
    ).toBe(true);

    // Die Seite liegt "lange offen": Was sie beim Laden selbst nachfragt - etwa die
    // Empfängervorschau des Editors, ein POST -, ist erledigt, bevor die Sitzung verfällt.
    await page.waitForLoadState('networkidle');
    const tokenBefore = await page.getAttribute('meta[name="csrf-token"]', 'content');

    await context.clearCookies({ name: 'PHPSESSID' });

    return tokenBefore;
}

/**
 * Die Sitzung verfällt, dann kehrt die Person zur Seite zurück: Die Glocke fragt nach
 * (public/js/notification-bell.js), und die AuthMiddleware baut die Sitzung still per
 * Remember-Me neu auf - mit einem neuen CSRF-Token. Genau so lief es in Produktion, bevor das
 * Speichern und Versenden der offenen Seite mit "Ungültiger CSRF-Token" scheiterte.
 */
export async function renewSessionThroughRememberMe(page) {
    const tokenBefore = await loseSessionKeepingRememberMe(page);

    // Dieselbe Abfrage, die die Glocke beim Zurückwechseln stellt - aus der Seite heraus, damit
    // sie über deren fetch läuft. Die Glocke selbst ist nicht in jeder Umgebung da: Sie
    // erscheint nur mit einem Modul, das Benachrichtigungen kennt (notification_badge()).
    const status = await page.evaluate(async () => {
        const response = await fetch('/notifications/badge', {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        return response.status;
    });
    expect(status, 'Die Abfrage muss die Sitzung per Remember-Me wiederherstellen').toBe(200);

    return tokenBefore;
}
