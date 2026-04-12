function initNewsletterCreate() {
    const form = document.getElementById("create-newsletter-form");
    if (!form) {
        return;
    }

    if (form.getAttribute("data-newsletter-create-init") === "1") {
        return;
    }
    form.setAttribute("data-newsletter-create-init", "1");

    const projectSelect = document.getElementById("project_id");
    const eventSelect = document.getElementById("event_id");
    const templateSelect = document.getElementById("template");
    const titleInput = document.getElementById("title");
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") || "";
    const isModal = form.getAttribute("data-is-modal") === "1";

    function filterEventsByProject() {
        if (!projectSelect || !eventSelect) {
            return;
        }

        const projectId = projectSelect.value;
        const options = eventSelect.querySelectorAll("option[data-project-id]");

        options.forEach(option => {
            const matches = option.getAttribute("data-project-id") === projectId;
            option.hidden = !matches;
            option.disabled = !matches;
        });

        const selectedOption = eventSelect.options[eventSelect.selectedIndex];
        if (
            selectedOption
            && selectedOption.hasAttribute("data-project-id")
            && selectedOption.getAttribute("data-project-id") !== projectId
        ) {
            eventSelect.value = "";
        }
    }

    if (projectSelect) {
        projectSelect.addEventListener("change", filterEventsByProject);
    }

    if (templateSelect) {
        templateSelect.addEventListener("change", async function () {
            if (!templateSelect.value) {
                return;
            }

            const response = await fetch(`/newsletters/template/${templateSelect.value}`);
            if (!response.ok) {
                return;
            }

            const data = await response.json();
            const editor = tinymce.get("content_html");
            if (editor) {
                editor.setContent(data.content_html || "");
            }
            if (titleInput) {
                titleInput.value = data.name || "";
            }
        });
    }

    // When running inside the newsletter modal, newsletters.js handles the submit at the
    // contentElement level (race-condition-free). Only attach here for direct page visits.
    if (typeof window.newsletterModalNavigate !== 'function') {
        form.addEventListener("submit", async function (event) {
            event.preventDefault();

            const formData = new FormData(form);
            const editor = typeof tinymce !== 'undefined' ? tinymce.get("content_html") : null;
            formData.set("content_html", editor ? editor.getContent() : "");

            if (csrfToken) {
                formData.set("_csrf", csrfToken);
            }

            const response = await fetch(form.getAttribute("action") || "/newsletters", {
                method: "POST",
                body: formData,
                headers: csrfToken ? { "X-CSRF-Token": csrfToken } : {},
            });

            if (!response.ok) {
                alert("Fehler beim Erstellen des Newsletters");
                return;
            }

            const data = await response.json();
            window.location.href = data.redirect;
        });
    }

    filterEventsByProject();
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initNewsletterCreate);
} else {
    initNewsletterCreate();
}
