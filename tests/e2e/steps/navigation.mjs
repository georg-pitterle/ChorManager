// Bausteine fuer die Hauptnavigation (templates/layout.twig).
//
// Selektoren aus templates/layout.twig:
//  - Burger-Button: button.navbar-toggler mit data-bs-target="#navbarsExampleDefault"
//  - einklappbarer Bereich: div#navbarsExampleDefault.collapse.navbar-collapse
//    (enthaelt ul.navbar-nav mit dem rechtegefilterten Menue)

import { expect } from '@playwright/test';

const TOGGLER = 'button.navbar-toggler';
const COLLAPSE = '#navbarsExampleDefault';

/**
 * Sorgt dafuer, dass die Menuelinks sichtbar sind.
 *
 * Unterhalb von Bootstraps lg-Breakpoint (mobiler Lauf, E2E_VIEWPORT=mobile) steckt das Menue
 * im Burger: die Links sind im DOM, aber unsichtbar. Pruefungen auf ":visible" waeren dort
 * sonst still wirkungslos - ein Test, der "kein verbotener Link sichtbar" erwartet, wuerde
 * gruen sein, ohne irgendetwas zu pruefen.
 *
 * Auf Desktop-Breite ist der Toggler ausgeblendet; die Funktion tut dann nichts.
 */
export async function openMainNavigation(page) {
    const toggler = page.locator(TOGGLER);
    if (!(await toggler.isVisible())) {
        return;
    }

    const collapse = page.locator(COLLAPSE);
    if (!(await collapse.isVisible())) {
        await toggler.click();
    }
    await collapse.waitFor({ state: 'visible' });
    // Bootstrap animiert das Ausklappen; erst danach stimmen die Sichtbarkeiten der Links.
    await expect(collapse).toHaveClass(/\bshow\b/);
}

/** Anzahl sichtbarer Menuelinks - Absicherung gegen still leere Navigations-Pruefungen. */
export async function visibleNavLinkCount(page) {
    return page.locator(`${COLLAPSE} a:visible`).count();
}
