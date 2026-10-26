/**
 * Suchfeld der Hilfe-Übersicht: blendet Anleitungen aus, die nicht zur Eingabe passen.
 *
 * Durchsucht wird je Thema der Titel und die Stichwörter seines Menüpunkts
 * (data-help-search) - nicht die Gruppe, sonst träfe "noten" die ganze Gruppe
 * "Noten & Dateien". Ohne Skript bleibt das Feld verborgen und die ganze Liste sichtbar.
 * Die Treffer-Logik ist eine reine Funktion, damit sie unter `node --test` prüfbar ist
 * (tests/js/help-index.test.mjs).
 */
(function (global) {
    'use strict';

    var rank = typeof module === 'object' && module.exports
        ? require('./navigation-search-rank.js')
        : global.NavigationSearchRank;

    /** Jedes Wort der Eingabe muss im Suchtext vorkommen. */
    function helpTopicMatches(searchText, query) {
        var haystack = rank.normalize(searchText);
        return rank.normalize(query).split(/\s+/).filter(Boolean).every(function (word) {
            return haystack.indexOf(word) !== -1;
        });
    }

    if (typeof module === 'object' && module.exports) {
        module.exports = { helpTopicMatches: helpTopicMatches };
        return;
    }

    document.addEventListener('DOMContentLoaded', function () {
        var filter = document.querySelector('[data-help-filter]');
        if (!filter || !rank) {
            return;
        }

        var input = filter.querySelector('input');
        var questions = document.querySelector('[data-help-questions]');
        var empty = document.querySelector('[data-help-filter-empty]');
        var groups = document.querySelectorAll('[data-help-group]');

        function apply() {
            var query = input.value;
            var searching = query.trim() !== '';
            var anyVisible = false;

            groups.forEach(function (group) {
                var groupVisible = false;
                group.querySelectorAll('[data-help-topic]').forEach(function (topic) {
                    var match = helpTopicMatches(topic.getAttribute('data-help-search') || '', query);
                    topic.classList.toggle('d-none', !match);
                    groupVisible = groupVisible || match;
                });
                group.classList.toggle('d-none', !groupVisible);
                anyVisible = anyVisible || groupVisible;
            });

            if (questions) {
                questions.classList.toggle('d-none', searching);
            }
            empty.classList.toggle('d-none', anyVisible);
        }

        filter.hidden = false;
        input.addEventListener('input', apply);
    });
})(this);
