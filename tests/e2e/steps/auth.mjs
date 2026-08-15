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

// Verifizierte Felder aus templates/auth/login.twig: email, password.
export async function login(page, { email, password }) {
    await page.goto('/login');
    await page.fill('input[name="email"]', email);
    await page.fill('input[name="password"]', password);
    await page.click('form[action="/login"] button[type="submit"]');
    await expect(page).not.toHaveURL(/\/login(\?|$)/);
}
