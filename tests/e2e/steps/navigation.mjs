// Bausteine für die Seitenleiste (templates/partials/navigation/sidebar.twig).
//
// Selektoren:
//  - Menüknopf in der Kopfleiste: button[data-nav-toggle]
//  - Leiste: aside#app-sidebar (ab 992 px fest sichtbar, darunter Bootstrap-Offcanvas)

import { expect } from '@playwright/test';

const TOGGLE = 'button[data-nav-toggle]';
const SIDEBAR = '#app-sidebar';

/**
 * Sorgt dafür, dass die Leistenlinks sichtbar sind.
 *
 * Unterhalb von Bootstraps lg-Breakpoint (mobiler Lauf, E2E_VIEWPORT=mobile) steckt die
 * Leiste im Offcanvas: die Links sind im DOM, aber unsichtbar. Prüfungen auf ":visible"
 * wären dort sonst still wirkungslos - ein Test, der "kein verbotener Link sichtbar"
 * erwartet, wäre grün, ohne irgendetwas zu prüfen.
 *
 * Am Desktop ist die Leiste immer sichtbar; die Funktion tut dann nichts.
 */
export async function openMainNavigation(page) {
    const sidebar = page.locator(SIDEBAR);
    if (await sidebar.isVisible()) {
        return;
    }

    await page.locator(TOGGLE).click();
    // Bootstrap animiert das Hereinfahren; erst mit "show" stimmen die Sichtbarkeiten.
    await expect(sidebar).toHaveClass(/\bshow\b/);
    await expect(sidebar).toBeVisible();
}

/** Anzahl sichtbarer Leistenlinks - Absicherung gegen still leere Navigations-Prüfungen. */
export async function visibleNavLinkCount(page) {
    return page.locator(`${SIDEBAR} a:visible`).count();
}
