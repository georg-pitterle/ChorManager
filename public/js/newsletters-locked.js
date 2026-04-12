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
