import { test, expect } from '@playwright/test';
import { PROTECTED_ROUTES, ROLE_MEMBERS, MEMBER_PASSWORD } from '../data/roleAccessMatrix.mjs';
import { createMember } from '../steps/members.mjs';
import { login } from '../steps/auth.mjs';
import { setMemberPassword, readRolePermissions } from '../steps/authz.mjs';

const BASE_URL = 'https://chormanager.ddev.site';

// Autorisierungs-Matrix: jede geseedete Rolle bekommt ein Mitglied mit GENAU dieser einen Rolle.
// Danach als dieses Mitglied anmelden und prüfen, dass es nur das sieht, wozu die Rolle laut ihren
// (aus der DB gelesenen) Rechten berechtigt ist:
//  - serverseitige Durchsetzung: geschützte Route -> 403, wenn das Recht fehlt; sonst erreichbar
//  - Navigation: kein Menü-Link in einen Bereich, für den das Recht fehlt
// Die Erwartung stammt aus den echten Rollen-Rechten (readRolePermissions), nicht aus fest
// verdrahteten Annahmen - so testet das Szenario, dass die Middleware/Session genau die
// DB-Rechte durchsetzt (fängt z. B. ein Recht, das beim Login nicht in die Session geladen wird).
test('Rollen-Autorisierung: jede Rolle sieht nur, wozu sie berechtigt ist', async ({ page, browser }) => {
    test.setTimeout(180_000);

    // 1. Als Admin (storageState) je Rolle ein Mitglied anlegen + Login-Passwort setzen.
    for (const member of ROLE_MEMBERS) {
        await createMember(page, member);
        setMemberPassword(member.email, MEMBER_PASSWORD);
    }

    // 2. Tatsächliche Rechte je Rolle als Quelle der erwarteten Matrix.
    const rolePerms = readRolePermissions();

    // 3. Pro Rolle in frischer Browser-Session anmelden und prüfen.
    for (const member of ROLE_MEMBERS) {
        const perms = rolePerms[member.role];
        expect(perms, `Rolle "${member.role}" muss in der DB existieren`).toBeTruthy();

        const context = await browser.newContext({ baseURL: BASE_URL, ignoreHTTPSErrors: true });
        // Eine frisch per browser.newContext() erzeugte Session erbt hier den Admin-storageState
        // aus der Config (use.storageState). Cookies leeren, damit wir uns wirklich als das
        // Mitglied (nicht als Admin) anmelden - sonst würde /login sofort nach /dashboard leiten.
        await context.clearCookies();
        const memberPage = await context.newPage();
        try {
            await login(memberPage, { email: member.email, password: MEMBER_PASSWORD });
            // Navigation einmal laden (rechtegefiltertes Menü).
            await memberPage.goto('/dashboard');

            for (const route of PROTECTED_ROUTES) {
                const allowed = route.requires.some((flag) => Number(perms[flag]) === 1);

                // (a) Serverseitige Durchsetzung über den echten Statuscode (Session-Cookie der
                //     angemeldeten Member-Session wird von page.request mitgeschickt).
                const status = (await memberPage.request.get(route.path)).status();
                if (allowed) {
                    expect(status, `${member.role} sollte ${route.path} erreichen dürfen`).not.toBe(403);
                } else {
                    expect(status, `${member.role} darf ${route.path} NICHT erreichen (erwarte 403)`).toBe(403);
                }

                // (b) Navigation: verbotener Bereich darf nicht verlinkt sein. Da das Menü
                //     serverseitig rechtegefiltert wird, ist bei fehlendem Recht KEIN Link mit
                //     diesem Pfad-Präfix sichtbar. (Erlaubte Bereiche werden nicht auf Präsenz
                //     geprüft, da das Menü u. U. eine andere Route desselben Bereichs verlinkt.)
                if (!allowed) {
                    const navLinks = memberPage.locator(`a[href^="${route.path}"]:visible`);
                    expect(
                        await navLinks.count(),
                        `${member.role}: es darf kein Navigations-Link zu ${route.path} sichtbar sein`
                    ).toBe(0);
                }
            }
        } finally {
            await context.close();
        }
    }
});
