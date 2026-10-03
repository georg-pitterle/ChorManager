/**
 * Läuft im <head>, bevor die Seite gezeichnet wird: setzt die gemerkten Zustände der
 * Seitenleiste als Klassen am <html>-Element. Würde das erst navigation.js am Ende der
 * Seite tun, spränge die Leiste bei jedem Seitenwechsel sichtbar von breit auf schmal.
 *
 * Gesperrter oder geleerter localStorage (privates Fenster) ist kein Fehler - dann
 * gilt der Standard: breite Leiste, Administration zu.
 */
(function () {
    'use strict';

    var root = document.documentElement;

    try {
        if (window.localStorage.getItem('chormanager.nav.collapsed') === '1') {
            root.classList.add('nav-collapsed');
        }
        if (window.localStorage.getItem('chormanager.nav.open.administration') === '1') {
            root.classList.add('nav-open-administration');
        }
    } catch (e) {
        // Standardzustand behalten.
    }
})();
