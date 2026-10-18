// Rückfragen der Sammelaktionen in der Mitgliederliste.
//
// Sie nennen vorher, was nachher passiert - und zwar zuerst die Zahl derer, die es
// wirklich trifft, dann wer übersprungen wird und wer schon eine Einladung hat.
// Vorher stand dort nur "Ausgewählte Mitglieder archivieren?", danach "80 Mitglieder
// archivieren? 26 davon werden übersprungen" - die größere, falsche Zahl zuerst.
(function (root) {
    function members(count) {
        return count === 1 ? '1 Mitglied' : count + ' Mitglieder';
    }

    function confirmTexts(stats) {
        const count = stats.count || 0;
        const offPage = stats.offPage || 0;
        const notArchivable = stats.notArchivable || 0;
        const alreadyInvited = stats.alreadyInvited || 0;
        const usesApp = stats.usesApp || 0;
        const scope = offPage > 0 ? ' (davon ' + offPage + ' auf anderen Seiten)' : '';
        const archivable = Math.max(0, count - notArchivable);
        const invitable = Math.max(0, count - usesApp);

        let archive = members(archivable) + scope + ' archivieren? ';
        if (notArchivable > 0) {
            archive += notArchivable + ' weitere ' + (notArchivable === 1 ? 'wird' : 'werden')
                + ' übersprungen (eigenes Konto oder erweiterte Rolle). ';
        }
        archive += 'Archivierte können sich nicht mehr anmelden; im Archiv lassen sie sich jederzeit wiederherstellen.';

        let invite = 'Einladung an ' + members(invitable) + scope + ' senden?';
        if (usesApp > 0) {
            invite += ' ' + usesApp + (usesApp === 1 ? ' weiteres nutzt' : ' weitere nutzen')
                + ' die App schon und ' + (usesApp === 1 ? 'wird' : 'werden') + ' übersprungen.';
        }
        if (alreadyInvited > 0) {
            invite += ' ' + alreadyInvited + ' davon ' + (alreadyInvited === 1 ? 'hat' : 'haben')
                + ' schon eine bekommen; ihr alter Link verliert seine Gültigkeit.';
        }

        return { archive: archive, invite: invite, archivable: archivable, invitable: invitable };
    }

    root.ChorUsersBulk = { confirmTexts: confirmTexts };
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = { confirmTexts: confirmTexts };
    }
})(typeof window !== 'undefined' ? window : globalThis);
