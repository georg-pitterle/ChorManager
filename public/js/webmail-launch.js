/**
 * Öffnet das Postfach in der installierten App im selben Fenster.
 *
 * Die Formulare zum Postfach tragen target="_blank", damit der ChorManager im
 * Browser neben dem Postfach offen bleibt. Läuft die Seite als installierte App
 * (PWA), öffnet der Browser ein solches Ziel außerhalb der App - und schickt dabei
 * GET statt POST. Ohne target bleibt es ein POST, und /webmail/ liegt im
 * Geltungsbereich der App.
 */
document.addEventListener('DOMContentLoaded', function () {
    var standalone = window.matchMedia('(display-mode: standalone)').matches
        || window.navigator.standalone === true;
    if (!standalone) {
        return;
    }

    document.querySelectorAll('form[data-webmail-launch]').forEach(function (form) {
        form.removeAttribute('target');
    });
});
