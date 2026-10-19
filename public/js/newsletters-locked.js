function initNewsletterLocked() {
    const reloadButton = document.getElementById("reload-locked-newsletter-btn");
    if (!reloadButton) {
        return;
    }

    if (reloadButton.getAttribute("data-newsletter-locked-init") === "1") {
        return;
    }
    reloadButton.setAttribute("data-newsletter-locked-init", "1");

    const isModalView = reloadButton.getAttribute("data-is-modal") === "1";

    function reloadLockedNewsletterView() {
        if (isModalView && typeof window.newsletterModalCloseAndRefresh === "function") {
            window.newsletterModalCloseAndRefresh();
            return;
        }

        window.location.reload();
    }

    reloadButton.addEventListener("click", function () {
        reloadLockedNewsletterView();
    });

    // Im Modal der Newsletter-Liste darf das Aufheben die Seite nicht verlassen: Die Antwort
    // nennt den Editor, und das Modal lädt ihn selbst nach. Die Rückfrage aus data-confirm
    // stellt common.js schon in der Capture-Phase; wer dort abbricht, kommt hier nicht weiter.
    const forceUnlockForm = document.getElementById("force-unlock-form");
    if (forceUnlockForm && isModalView) {
        forceUnlockForm.addEventListener("submit", async function (event) {
            if (event.defaultPrevented) {
                return;
            }
            event.preventDefault();

            const response = await fetch(forceUnlockForm.getAttribute("action") || "", {
                method: "POST",
                body: new FormData(forceUnlockForm),
                headers: { "X-Requested-With": "XMLHttpRequest" },
                credentials: "same-origin",
            });
            const payload = await response.json().catch(function () {
                return null;
            });

            if (!response.ok || !payload || !payload.redirect) {
                alert(payload && payload.error ? payload.error : "Die Sperre konnte nicht aufgehoben werden.");
                return;
            }

            if (typeof window.newsletterModalNavigate === "function") {
                window.newsletterModalNavigate(payload.redirect, "Newsletter bearbeiten");
                return;
            }

            window.location.href = payload.redirect.replace("?modal=1", "");
        });
    }

    const reloadIntervalId = setInterval(function () {
        if (!document.body.contains(reloadButton)) {
            clearInterval(reloadIntervalId);
            return;
        }

        reloadLockedNewsletterView();
    }, 30000);
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initNewsletterLocked);
} else {
    initNewsletterLocked();
}
