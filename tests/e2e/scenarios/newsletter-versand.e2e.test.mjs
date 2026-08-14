import { test, expect } from '@playwright/test';
import {
    EDITOR_PRIMARY,
    EDITOR_SECOND,
    PROJECT_MEMBERS,
    OUTSIDER,
    NEWSLETTER_PASSWORD,
    NEWSLETTER_PROJECT,
    TEMPLATE,
    NEWSLETTER_WITHOUT_TEMPLATE,
    NEWSLETTER_WITH_TEMPLATE,
    NEWSLETTER_COMBINED,
    NEWSLETTER_WITHOUT_RECIPIENTS,
    NEWSLETTER_LOCKED,
} from '../data/newsletters.mjs';
import {
    CONCERT_EVENT,
    CONCERT_EDITOR,
    CONCERT_PRESENT,
    CONCERT_EXCUSED,
    NEWSLETTER_EVENT_REPORT,
    REUSE_EDITOR,
    REUSE_RECIPIENT,
    NEWSLETTER_TO_REUSE,
    SAVED_TEMPLATE,
    NEWSLETTER_REUSING_TEMPLATE,
    LATE_PROJECT,
    LATE_EDITOR,
    LATE_EARLY_MEMBER,
    LATE_JOINER,
    NEWSLETTER_LATE_JOINER,
} from '../data/newsletters.mjs';
import { createMember } from '../steps/members.mjs';
import { createProject, addProjectMember } from '../steps/projects.mjs';
import { createEvent, markAttendance } from '../steps/events.mjs';
import { login } from '../steps/auth.mjs';
import { setMemberPassword, readRolePermissions } from '../steps/authz.mjs';
import {
    isNewsletterModuleEnabled,
    createNewsletterDraft,
    createNewsletterTemplate,
    sendOpenNewsletter,
    readRecipientCount,
    listNewsletterTitles,
    openEditPage,
    sendFromEditPage,
    saveOpenNewsletterAsTemplate,
} from '../steps/newsletters.mjs';
import { deliverQueuedMails, mailpitRecipientsForSubject, mailpitBodyForSubject } from '../steps/mail.mjs';

const BASE_URL = 'https://chormanager.ddev.site';

async function asUser(browser, email, run) {
    const context = await browser.newContext({ baseURL: BASE_URL, ignoreHTTPSErrors: true });
    // Ein frischer Kontext erbt den Admin-storageState aus der Config - Cookies leeren, sonst
    // leitet /login sofort nach /dashboard weiter, und wir waeren weiter als Admin unterwegs.
    await context.clearCookies();
    const page = await context.newPage();
    try {
        await login(page, { email, password: NEWSLETTER_PASSWORD });
        await run(page);
    } finally {
        await context.close();
    }
}

// Legt die gemeinsame Ausgangslage als Admin an: Redakteure, Empfaenger, Projekt, Vorlage.
async function bootstrapNewsletterFixtures(page) {
    for (const person of [EDITOR_PRIMARY, EDITOR_SECOND, ...PROJECT_MEMBERS, OUTSIDER]) {
        await createMember(page, person);
        setMemberPassword(person.email, NEWSLETTER_PASSWORD);
    }

    await createProject(page, NEWSLETTER_PROJECT);
    for (const member of PROJECT_MEMBERS) {
        await addProjectMember(page, NEWSLETTER_PROJECT.name, member.lastName);
    }
}

test('Newsletter-Versand: Vorlagen und Empfaengerkombinationen', async ({ page, browser }) => {
    test.setTimeout(300_000);

    test.skip(!(await isNewsletterModuleEnabled(page)), 'Newsletter-Modul ist in dieser Umgebung aus.');

    // Die Redakteure brauchen das Recht "Newsletter verwalten". Die Erwartung stammt aus den
    // echten Rollenrechten in der DB, nicht aus einer Annahme im Testcode.
    const rolePerms = readRolePermissions();
    for (const editor of [EDITOR_PRIMARY, EDITOR_SECOND]) {
        expect(
            Number(rolePerms[editor.role]?.can_manage_newsletters),
            `Rolle "${editor.role}" muss can_manage_newsletters besitzen`
        ).toBe(1);
    }

    await bootstrapNewsletterFixtures(page);
    await createNewsletterTemplate(page, {
        name: TEMPLATE.name,
        description: TEMPLATE.description,
        content: TEMPLATE.marker,
    });

    // Ab hier arbeitet die Redakteurin - sie ist bewusst in KEINEM Projekt Mitglied. Seit der
    // Entkopplung genuegt das Recht; frueher haette sie hier eine leere Oberflaeche gesehen.
    await asUser(browser, EDITOR_PRIMARY.email, async (editorPage) => {
        // 1. Ohne Vorlage an die Projektmitglieder: exakt die drei zugeordneten Personen.
        await createNewsletterDraft(editorPage, {
            title: NEWSLETTER_WITHOUT_TEMPLATE.title,
            project: NEWSLETTER_PROJECT.name,
            content: NEWSLETTER_WITHOUT_TEMPLATE.marker,
            sources: { project_members: [NEWSLETTER_PROJECT.name] },
        });
        expect(
            await readRecipientCount(editorPage),
            'Projektquelle muss genau die drei zugeordneten Mitglieder aufloesen'
        ).toBe(PROJECT_MEMBERS.length);
        await sendOpenNewsletter(editorPage);

        // Bis zur fertigen Mail: Der Versand stellt nur in die Warteschlange, zugestellt wird
        // vom Worker. In der Dev-Umgebung landen die Mails danach in Mailpit.
        deliverQueuedMails(NEWSLETTER_WITHOUT_TEMPLATE.title);
        const delivered = await mailpitRecipientsForSubject(editorPage.request, NEWSLETTER_WITHOUT_TEMPLATE.title);
        expect(
            delivered.sort(),
            'Zugestellt werden muss an genau die drei Projektmitglieder'
        ).toEqual(PROJECT_MEMBERS.map((member) => member.email).sort());
        expect(
            await mailpitBodyForSubject(editorPage.request, NEWSLETTER_WITHOUT_TEMPLATE.title),
            'Die Mail muss den geschriebenen Inhalt tragen'
        ).toContain(NEWSLETTER_WITHOUT_TEMPLATE.marker);

        // 2. Mit Vorlage an einzeln gewaehlte Personen, ohne Projektbezug.
        await createNewsletterDraft(editorPage, {
            title: NEWSLETTER_WITH_TEMPLATE.title,
            project: null,
            template: TEMPLATE.name,
            sources: { user: [PROJECT_MEMBERS[0].lastName, OUTSIDER.lastName] },
        });
        expect(
            await readRecipientCount(editorPage),
            'Einzelauswahl muss genau die zwei gewaehlten Personen aufloesen'
        ).toBe(2);
        await sendOpenNewsletter(editorPage);

        // 3. Kombination aus Projekt und einer Person, die bereits im Projekt ist:
        //    Doppelte werden zusammengefuehrt, die Zahl bleibt bei drei.
        await createNewsletterDraft(editorPage, {
            title: NEWSLETTER_COMBINED.title,
            project: NEWSLETTER_PROJECT.name,
            content: NEWSLETTER_COMBINED.marker,
            sources: {
                project_members: [NEWSLETTER_PROJECT.name],
                user: [PROJECT_MEMBERS[0].lastName],
            },
        });
        expect(
            await readRecipientCount(editorPage),
            'Ueberschneidende Quellen duerfen niemanden doppelt erfassen'
        ).toBe(PROJECT_MEMBERS.length);
        await sendOpenNewsletter(editorPage);

        // 4. Ohne Empfaengerquelle: speicherbar, aber der Versand ist gesperrt.
        await createNewsletterDraft(editorPage, {
            title: NEWSLETTER_WITHOUT_RECIPIENTS.title,
            project: null,
            content: NEWSLETTER_WITHOUT_RECIPIENTS.marker,
            sources: {},
        });
        expect(await readRecipientCount(editorPage), 'ohne Quelle darf niemand aufgeloest werden').toBe(0);
        await expect(
            editorPage.locator('#newsletterActionContent #send-newsletter-btn'),
            'Versenden muss ohne Empfaenger gesperrt sein'
        ).toBeDisabled();

        // Der Entwurf bleibt trotzdem erhalten, die drei versendeten sind aus den Entwuerfen weg.
        const drafts = await listNewsletterTitles(editorPage, { status: 'draft' });
        expect(drafts.join(' | ')).toContain(NEWSLETTER_WITHOUT_RECIPIENTS.title);
        expect(drafts.join(' | ')).not.toContain(NEWSLETTER_WITHOUT_TEMPLATE.title);

        const sent = await listNewsletterTitles(editorPage, { status: 'sent' });
        for (const title of [
            NEWSLETTER_WITHOUT_TEMPLATE.title,
            NEWSLETTER_WITH_TEMPLATE.title,
            NEWSLETTER_COMBINED.title,
        ]) {
            expect(sent.join(' | '), `"${title}" muss unter den versendeten stehen`).toContain(title);
        }

        // Der projektlose Newsletter taucht unter "Ohne Projekt" auf, der projektgebundene nicht.
        const projectless = await listNewsletterTitles(editorPage, { status: 'sent', projectFilter: 'none' });
        expect(projectless.join(' | ')).toContain(NEWSLETTER_WITH_TEMPLATE.title);
        expect(projectless.join(' | ')).not.toContain(NEWSLETTER_WITHOUT_TEMPLATE.title);
    });

    // Gegenprobe bei den Empfaengern: Wer erreicht wurde, findet den Newsletter im eigenen Archiv;
    // wer ausserhalb der Quelle lag, findet ihn dort nicht.
    await asUser(browser, PROJECT_MEMBERS[1].email, async (memberPage) => {
        await memberPage.goto('/newsletters/archive');
        const archive = await memberPage.locator('#newsletterArchiveTable tbody').innerText();
        expect(archive).toContain(NEWSLETTER_WITHOUT_TEMPLATE.title);
        expect(archive).toContain(NEWSLETTER_COMBINED.title);
        // An dieser Person ging der Newsletter aus Vorlage vorbei - sie war nicht einzeln gewaehlt.
        expect(archive).not.toContain(NEWSLETTER_WITH_TEMPLATE.title);
    });

    await asUser(browser, OUTSIDER.email, async (outsiderPage) => {
        await outsiderPage.goto('/newsletters/archive');
        const archive = await outsiderPage.locator('#newsletterArchiveTable tbody').innerText();
        // Nicht im Projekt, aber einzeln adressiert: nur der Newsletter aus der Vorlage.
        expect(archive).toContain(NEWSLETTER_WITH_TEMPLATE.title);
        expect(archive).not.toContain(NEWSLETTER_WITHOUT_TEMPLATE.title);
        expect(archive).not.toContain(NEWSLETTER_COMBINED.title);
    });
});

test('Newsletter-Sperre: zwei Redakteure am selben Entwurf', async ({ page, browser }) => {
    test.setTimeout(180_000);

    test.skip(!(await isNewsletterModuleEnabled(page)), 'Newsletter-Modul ist in dieser Umgebung aus.');

    // Eigene Personen fuer diesen Test, damit er unabhaengig vom Versand-Szenario laeuft.
    const lockEditorA = { ...EDITOR_PRIMARY, email: 'nl.lock.a@chor.local', firstName: 'Lena', lastName: 'Sperrend' };
    const lockEditorB = { ...EDITOR_SECOND, email: 'nl.lock.b@chor.local', firstName: 'Björn', lastName: 'Wartend' };

    for (const person of [lockEditorA, lockEditorB]) {
        await createMember(page, person);
        setMemberPassword(person.email, NEWSLETTER_PASSWORD);
    }

    let newsletterId = 0;
    await asUser(browser, lockEditorA.email, async (pageA) => {
        newsletterId = await createNewsletterDraft(pageA, {
            title: NEWSLETTER_LOCKED.title,
            project: null,
            content: NEWSLETTER_LOCKED.marker,
            sources: { user: [lockEditorB.lastName] },
        });
        expect(newsletterId, 'Der Entwurf muss eine ID bekommen haben').toBeGreaterThan(0);

        // Erst die Uebersicht verlassen: Das noch offene Modal gibt seine Sperre beim Entladen
        // per Beacon frei. Wuerde direkt von dort auf die Bearbeiten-Seite gewechselt, koennte
        // die Freigabe die frisch gesetzte Sperre wieder aufheben (Wettlauf).
        await pageA.goto('/dashboard');

        // Bearbeiten-Seite direkt oeffnen: dadurch haelt diese Sitzung die Sperre, solange die
        // Seite offen bleibt.
        await pageA.goto(`/newsletters/${newsletterId}/edit`);
        await pageA.locator('#edit-newsletter-form').waitFor({ state: 'visible' });

        // Vorbedingung beweisen statt annehmen: Die Sperre gehoert jetzt dieser Sitzung.
        const lockInfo = await (await pageA.request.get(`/newsletters/${newsletterId}/check-lock`)).json();
        expect(lockInfo.locked, 'Das Oeffnen der Bearbeiten-Seite muss den Entwurf sperren').toBe(true);
        expect(lockInfo.is_me, 'Die Sperre muss der ersten Sitzung gehoeren').toBe(true);

        // Zweiter Redakteur waehrend der laufenden Bearbeitung: ausgesperrt.
        await asUser(browser, lockEditorB.email, async (pageB) => {
            const status = (await pageB.request.get(`/newsletters/${newsletterId}/edit`)).status();
            expect(status, 'Ein gesperrter Entwurf muss mit 423 abgewiesen werden').toBe(423);

            await pageB.goto(`/newsletters/${newsletterId}/edit`);
            const body = await pageB.locator('body').innerText();
            expect(body).toContain('bearbeitet');
            expect(body, 'Die sperrende Person muss genannt werden').toContain(lockEditorA.lastName);

            // Das Bearbeitungsformular darf fuer die zweite Person nicht erscheinen.
            expect(await pageB.locator('#edit-newsletter-form').count()).toBe(0);
        });

        // Erste Sitzung verlaesst die Seite -> die Sperre wird beim Entladen freigegeben.
        await pageA.goto('/dashboard');
    });

    // Danach kann die zweite Person denselben Entwurf oeffnen.
    await asUser(browser, lockEditorB.email, async (pageB) => {
        await pageB.goto(`/newsletters/${newsletterId}/edit`);
        await expect(
            pageB.locator('#edit-newsletter-form'),
            'Nach Freigabe der Sperre muss der Entwurf wieder bearbeitbar sein'
        ).toBeVisible();

        // Sauber verlassen und die Freigabe abwarten: Ein zurueckgelassener gesperrter Entwurf
        // wuerde jede spaetere Bearbeiten-Anfrage mit 423 beantworten - auch die des Crawlers,
        // der nach den Szenarien ueber dieselbe DB laeuft.
        await pageB.goto('/dashboard');
        await expect
            .poll(
                async () => (await (await pageB.request.get(`/newsletters/${newsletterId}/check-lock`)).json()).locked,
                { message: 'Die Sperre muss nach dem Verlassen der Seite freigegeben sein' }
            )
            .toBe(false);
    });
});

test('Nachbericht: nur wer beim Termin anwesend war, bekommt den Newsletter', async ({ page, browser }) => {
    test.setTimeout(240_000);

    test.skip(!(await isNewsletterModuleEnabled(page)), 'Newsletter-Modul ist in dieser Umgebung aus.');

    // Praxisfall: Nach einem Auftritt geht ein Dankeschoen an die Mitwirkenden - nicht an alle.
    for (const person of [CONCERT_EDITOR, ...CONCERT_PRESENT, CONCERT_EXCUSED]) {
        await createMember(page, person);
        setMemberPassword(person.email, NEWSLETTER_PASSWORD);
    }

    await createEvent(page, CONCERT_EVENT);
    await markAttendance(page, CONCERT_EVENT.title, [
        ...CONCERT_PRESENT.map((person) => ({ name: person.lastName, status: 'present' })),
        { name: CONCERT_EXCUSED.lastName, status: 'excused' },
    ]);

    await asUser(browser, CONCERT_EDITOR.email, async (editorPage) => {
        const newsletterId = await createNewsletterDraft(editorPage, {
            title: NEWSLETTER_EVENT_REPORT.title,
            project: null,
            content: NEWSLETTER_EVENT_REPORT.marker,
            sources: { event_attendees: [CONCERT_EVENT.title] },
        });

        // Entschuldigte zaehlen nicht als Teilnehmende - nur die beiden Anwesenden.
        expect(
            await readRecipientCount(editorPage),
            'Die Terminquelle darf nur die anwesenden Personen aufloesen'
        ).toBe(CONCERT_PRESENT.length);

        await sendOpenNewsletter(editorPage);

        // Bis zur fertigen Mail: Nur die Anwesenden duerfen eine bekommen.
        deliverQueuedMails(NEWSLETTER_EVENT_REPORT.title);
        const delivered = await mailpitRecipientsForSubject(editorPage.request, NEWSLETTER_EVENT_REPORT.title);
        expect(delivered.sort(), 'Zugestellt werden muss an genau die Anwesenden')
            .toEqual(CONCERT_PRESENT.map((person) => person.email).sort());
        expect(delivered, 'Die entschuldigte Person darf keine Mail bekommen')
            .not.toContain(CONCERT_EXCUSED.email);
    });

    await asUser(browser, CONCERT_PRESENT[0].email, async (presentPage) => {
        await presentPage.goto('/newsletters/archive');
        const archive = await presentPage.locator('#newsletterArchiveTable tbody').innerText();
        expect(archive, 'Anwesende muessen den Nachbericht erhalten').toContain(NEWSLETTER_EVENT_REPORT.title);
    });

    await asUser(browser, CONCERT_EXCUSED.email, async (excusedPage) => {
        await excusedPage.goto('/newsletters/archive');
        const archive = await excusedPage.locator('#newsletterArchiveTable tbody').innerText();
        expect(archive, 'Entschuldigte duerfen den Nachbericht nicht erhalten')
            .not.toContain(NEWSLETTER_EVENT_REPORT.title);
    });
});

test('Wiederverwendung: bewaehrtes Rundschreiben als Vorlage sichern und erneut nutzen', async ({ page, browser }) => {
    test.setTimeout(240_000);

    test.skip(!(await isNewsletterModuleEnabled(page)), 'Newsletter-Modul ist in dieser Umgebung aus.');

    // Praxisfall: Ein gelungener Newsletter soll beim naechsten Mal nicht neu getippt werden.
    for (const person of [REUSE_EDITOR, REUSE_RECIPIENT]) {
        await createMember(page, person);
        setMemberPassword(person.email, NEWSLETTER_PASSWORD);
    }

    await asUser(browser, REUSE_EDITOR.email, async (editorPage) => {
        const newsletterId = await createNewsletterDraft(editorPage, {
            title: NEWSLETTER_TO_REUSE.title,
            project: null,
            content: NEWSLETTER_TO_REUSE.marker,
            sources: { user: [REUSE_RECIPIENT.lastName] },
        });

        // Auf der Bearbeiten-Seite (nicht im Modal) laesst sich der Inhalt als Vorlage sichern.
        await openEditPage(editorPage, newsletterId);
        const message = await saveOpenNewsletterAsTemplate(editorPage, SAVED_TEMPLATE);
        expect(message, 'Das Sichern als Vorlage muss bestaetigt werden').toContain('Vorlage');

        // Die gesicherte Vorlage steht sofort in der Vorlagenverwaltung.
        await editorPage.goto('/newsletters/templates');
        await expect(
            editorPage.locator('#newsletterTemplatesTable tbody'),
            'Die gesicherte Vorlage muss in der Verwaltung auftauchen'
        ).toContainText(SAVED_TEMPLATE.name);

        // Und laesst sich in einen neuen Newsletter laden - der Inhalt kommt aus dem Original.
        await createNewsletterDraft(editorPage, {
            title: NEWSLETTER_REUSING_TEMPLATE.title,
            project: null,
            template: SAVED_TEMPLATE.name,
            sources: { user: [REUSE_RECIPIENT.lastName] },
        });

        const editorBody = editorPage.frameLocator('#newsletterActionContent .tox-edit-area iframe').locator('body');
        await expect(editorBody, 'Der Vorlageninhalt muss aus dem Original stammen')
            .toContainText(NEWSLETTER_TO_REUSE.marker);

        await sendOpenNewsletter(editorPage);
    });

    await asUser(browser, REUSE_RECIPIENT.email, async (recipientPage) => {
        await recipientPage.goto('/newsletters/archive');
        const archive = await recipientPage.locator('#newsletterArchiveTable tbody').innerText();
        expect(archive).toContain(NEWSLETTER_REUSING_TEMPLATE.title);
    });
});

test('Spaete Zuordnung: der Empfaengerkreis wird erst beim Versand aufgeloest', async ({ page, browser }) => {
    test.setTimeout(240_000);

    test.skip(!(await isNewsletterModuleEnabled(page)), 'Newsletter-Modul ist in dieser Umgebung aus.');

    // Praxisfall: Der Entwurf liegt ein paar Tage. In der Zwischenzeit kommt jemand ins Projekt -
    // beim Versand muss die Person mitkommen, ohne dass der Entwurf angefasst wurde.
    for (const person of [LATE_EDITOR, LATE_EARLY_MEMBER, LATE_JOINER]) {
        await createMember(page, person);
        setMemberPassword(person.email, NEWSLETTER_PASSWORD);
    }
    await createProject(page, LATE_PROJECT);
    await addProjectMember(page, LATE_PROJECT.name, LATE_EARLY_MEMBER.lastName);

    let newsletterId = 0;
    await asUser(browser, LATE_EDITOR.email, async (editorPage) => {
        newsletterId = await createNewsletterDraft(editorPage, {
            title: NEWSLETTER_LATE_JOINER.title,
            project: LATE_PROJECT.name,
            content: NEWSLETTER_LATE_JOINER.marker,
            sources: { project_members: [LATE_PROJECT.name] },
        });
        expect(
            await readRecipientCount(editorPage),
            'Zum Zeitpunkt des Speicherns ist nur eine Person im Projekt'
        ).toBe(1);

        // Bearbeitung beenden, damit die Sperre frei wird und der Entwurf unangetastet liegt.
        await editorPage.goto('/dashboard');
    });

    // Zwischenzeitlich tritt eine weitere Person dem Projekt bei (durch die Verwaltung).
    await addProjectMember(page, LATE_PROJECT.name, LATE_JOINER.lastName);

    await asUser(browser, LATE_EDITOR.email, async (editorPage) => {
        await openEditPage(editorPage, newsletterId);
        await sendFromEditPage(editorPage);

        // Bis zur fertigen Mail: Auch die spaet zugeordnete Person muss eine bekommen.
        deliverQueuedMails(NEWSLETTER_LATE_JOINER.title);
        const delivered = await mailpitRecipientsForSubject(editorPage.request, NEWSLETTER_LATE_JOINER.title);
        expect(delivered.sort(), 'Beide Projektmitglieder muessen eine Mail erhalten').toEqual(
            [LATE_EARLY_MEMBER.email, LATE_JOINER.email].sort()
        );
    });

    // Beide Personen muessen den Newsletter bekommen haben - auch die spaet zugeordnete.
    for (const person of [LATE_EARLY_MEMBER, LATE_JOINER]) {
        await asUser(browser, person.email, async (memberPage) => {
            await memberPage.goto('/newsletters/archive');
            const archive = await memberPage.locator('#newsletterArchiveTable tbody').innerText();
            expect(
                archive,
                `${person.lastName} muss den Newsletter erhalten haben`
            ).toContain(NEWSLETTER_LATE_JOINER.title);
        });
    }
});
