(function (root) {
    "use strict";

    // Erklärvideos liegen nicht gelistet auf YouTube. Die Seite zeigt nur ein
    // lokales Vorschaubild; erst der Klick auf "Video laden" setzt den Rahmen
    // der cookielosen YouTube-Adresse ein. So geht ohne Zutun nichts an fremde
    // Server, und die CSP muss frame-src nur für diese eine Adresse öffnen.
    const HOST = "https://www.youtube-nocookie.com/embed/";
    const ID = /^[A-Za-z0-9_-]{11}$/;

    // Dieselbe Grenze wie im Stylesheet (.video-embed--has-portrait): darunter
    // steht der Rahmen im Hochformat.
    const NARROW = "(max-width: 575.98px)";

    // Welche Fassung geladen wird: auf schmalen Bildschirmen das Hochformat,
    // sofern es eines gibt, sonst das Querformat.
    function chooseVideo(dataset, isNarrow) {
        const portrait = dataset.videoPortraitId || "";
        if (isNarrow && ID.test(portrait)) {
            return { id: portrait, portrait: true };
        }

        const id = dataset.videoId || "";
        return ID.test(id) ? { id: id, portrait: false } : null;
    }

    if (typeof module !== "undefined" && module.exports) {
        module.exports = { chooseVideo: chooseVideo };
    }

    const document = root.document;
    if (!document) {
        return;
    }

    function load(figure) {
        const isNarrow = typeof root.matchMedia === "function" && root.matchMedia(NARROW).matches;
        const choice = chooseVideo(figure.dataset, isNarrow);
        const holder = figure.querySelector(".video-embed-frame");
        if (!choice || !holder) {
            return;
        }

        const frame = document.createElement("iframe");
        frame.src = HOST + choice.id + "?autoplay=1&rel=0";
        frame.title = figure.dataset.videoTitle || "Video";
        frame.allow = "autoplay; encrypted-media; picture-in-picture; fullscreen";
        frame.allowFullscreen = true;
        frame.referrerPolicy = "strict-origin-when-cross-origin";
        frame.className = "video-embed-player";

        // Lädt auf einem schmalen Bildschirm doch das Querformat, darf der
        // Rahmen nicht im Hochformat stehen bleiben.
        figure.classList.toggle("video-embed--portrait-loaded", choice.portrait);
        figure.classList.toggle("video-embed--landscape-loaded", !choice.portrait);
        holder.replaceChildren(frame);
        frame.focus();
    }

    document.addEventListener("click", function (event) {
        const trigger = event.target.closest("[data-video-load]");
        const figure = trigger ? trigger.closest("[data-video-embed]") : null;
        if (figure) {
            load(figure);
        }
    });

    // Die Kurzanleitung steht beim ersten Besuch offen. Wer sie zuklappt, hat
    // sie gelesen - das merkt sich der Browser, nicht der Server.
    document.querySelectorAll("details[data-remember-open]").forEach(function (details) {
        const key = "details-open:" + details.dataset.rememberOpen;
        try {
            const stored = root.localStorage.getItem(key);
            if (stored !== null) {
                details.open = stored === "1";
            }
        } catch (error) {
            // Ohne Speicher bleibt es beim Zustand aus dem Markup.
        }

        details.addEventListener("toggle", function () {
            try {
                root.localStorage.setItem(key, details.open ? "1" : "0");
            } catch (error) {
                // siehe oben
            }
        });
    });
})(typeof window !== "undefined" ? window : globalThis);
