/**
 * Reine Suchlogik der Schnellsuche - ohne DOM, damit sie unter `node --test` prüfbar ist
 * (tests/js/navigation-search-rank.test.mjs). Im Browser hängt sie an
 * window.NavigationSearchRank, in Node an module.exports.
 */
(function (global) {
    'use strict';

    function normalize(text) {
        return String(text).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').trim();
    }

    /**
     * Reihenfolge: Name beginnt mit der Eingabe, Name enthält sie, Abschnitt enthält sie,
     * ein Stichwort enthält sie. Innerhalb einer Stufe bleibt die Reihenfolge der Leiste.
     */
    function rankNavigationEntries(entries, query) {
        var needle = normalize(query);
        if (needle === '') {
            return [];
        }

        var hits = [];
        entries.forEach(function (entry, index) {
            var label = normalize(entry.label);
            var score = null;
            var keyword = null;

            if (label.indexOf(needle) === 0) {
                score = 0;
            } else if (label.indexOf(needle) !== -1) {
                score = 1;
            } else if (normalize(entry.section).indexOf(needle) !== -1) {
                score = 2;
            } else {
                for (var i = 0; i < entry.keywords.length; i++) {
                    if (normalize(entry.keywords[i]).indexOf(needle) !== -1) {
                        score = 3;
                        keyword = entry.keywords[i];
                        break;
                    }
                }
            }

            if (score !== null) {
                hits.push({ entry: entry, keyword: keyword, score: score, index: index });
            }
        });

        hits.sort(function (a, b) {
            return a.score - b.score || a.index - b.index;
        });

        return hits.map(function (hit) {
            return { entry: hit.entry, keyword: hit.keyword };
        });
    }

    function readRecentUrls(raw) {
        try {
            var parsed = JSON.parse(raw || '[]');
            return Array.isArray(parsed)
                ? parsed.filter(function (url) { return typeof url === 'string'; })
                : [];
        } catch (e) {
            return [];
        }
    }

    /** Ein gemerkter Pfad erscheint nur, solange die Leiste ihn noch für diese Rolle zeigt. */
    function resolveRecentEntries(entries, urls) {
        var result = [];
        urls.forEach(function (url) {
            for (var i = 0; i < entries.length; i++) {
                if (entries[i].url === url) {
                    result.push(entries[i]);
                    return;
                }
            }
        });
        return result;
    }

    var api = {
        normalize: normalize,
        rankNavigationEntries: rankNavigationEntries,
        readRecentUrls: readRecentUrls,
        resolveRecentEntries: resolveRecentEntries,
    };

    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    } else {
        global.NavigationSearchRank = api;
    }
})(typeof window !== 'undefined' ? window : globalThis);
