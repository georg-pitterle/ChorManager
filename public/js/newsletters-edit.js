function initNewsletterEdit() {
    const editForm = document.getElementById("edit-newsletter-form");
    const sendForm = document.getElementById("send-form");
    if (!editForm || !sendForm) {
        return;
    }

    if (editForm.getAttribute("data-newsletter-edit-init") === "1") {
        return;
    }
    editForm.setAttribute("data-newsletter-edit-init", "1");

    const isModalView = editForm.getAttribute("data-is-modal") === "1";
    const newsletterId = editForm.getAttribute("data-newsletter-id") || "";
    const newsletterUpdateUrl = editForm.getAttribute("action") || "";
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") || "";

    const projectSelect = document.getElementById("project_id");
    const eventSelect = document.getElementById("event_id");
    const titleInput = document.getElementById("title");
    const saveDraftButton = document.getElementById("save-draft-btn");
    const previewButton = document.getElementById("preview-btn");
    const sendButton = document.getElementById("send-newsletter-btn");
    const saveTemplateButton = document.getElementById("save-template-btn");
    const templateNameInput = document.getElementById("template_name");
    const templateDescriptionInput = document.getElementById("template_description");

    let lastSavedSnapshot = null;
    let saveInProgress = false;

    function showEditAlert(type, message) {
        if (!message) {
            return;
        }

        editForm.querySelectorAll('.newsletter-edit-alert').forEach(alertEl => {
            alertEl.remove();
        });

        const wrapper = document.createElement('div');
        wrapper.className = `alert alert-${type} alert-dismissible fade show newsletter-edit-alert`;
        wrapper.setAttribute('role', 'alert');
        wrapper.textContent = String(message);

        const closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'btn-close';
        closeBtn.setAttribute('data-bs-dismiss', 'alert');
        closeBtn.setAttribute('aria-label', 'Close');
        wrapper.appendChild(closeBtn);

        editForm.prepend(wrapper);
    }

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

    function createSnapshot() {
        const projectId = projectSelect ? projectSelect.value : "";
        const title = titleInput ? titleInput.value : "";
        const eventId = eventSelect ? eventSelect.value : "";
        const editor = tinymce.get("content_html");
        const contentHtml = editor ? editor.getContent() : "";

        return JSON.stringify({
            project_id: projectId,
            title: title,
            event_id: eventId,
            content_html: contentHtml,
        });
    }

    async function saveNewsletter(showSuccessMessage) {
        if (saveInProgress || !newsletterUpdateUrl) {
            return;
        }

        const currentSnapshot = createSnapshot();
        if (lastSavedSnapshot !== null && currentSnapshot === lastSavedSnapshot) {
            return;
        }

        saveInProgress = true;
        const formData = new FormData(editForm);
        const editor = tinymce.get("content_html");
        formData.set("content_html", editor ? editor.getContent() : "");

        if (!showSuccessMessage) {
            formData.set("suppress_flash", "1");
        }

        if (csrfToken) {
            formData.set("_csrf", csrfToken);
        }

        try {
            const response = await fetch(newsletterUpdateUrl, {
                method: "POST",
                body: formData,
                headers: {
                    "X-Requested-With": "XMLHttpRequest",
                    ...(csrfToken ? { "X-CSRF-Token": csrfToken } : {}),
                },
            });

            if (!response.ok) {
                if (showSuccessMessage) {
                    showEditAlert("danger", "Fehler beim Speichern");
                }
                return;
            }

            lastSavedSnapshot = currentSnapshot;
            if (showSuccessMessage) {
                if (isModalView && typeof window.newsletterModalCloseAndRefresh === "function") {
                    window.newsletterModalCloseAndRefresh();
                    return;
                }

                showEditAlert("success", "Newsletter gespeichert");
            }
        } finally {
            saveInProgress = false;
        }
    }

    editForm.addEventListener("submit", function (event) {
        event.preventDefault();
        saveNewsletter(true);
    });

    if (projectSelect) {
        projectSelect.addEventListener("change", filterEventsByProject);
    }

    if (saveDraftButton) {
        saveDraftButton.addEventListener("click", function () {
            saveNewsletter(true);
        });
    }

    if (previewButton) {
        previewButton.addEventListener("click", function () {
            if (isModalView && newsletterId && typeof window.newsletterModalNavigate === "function") {
                window.newsletterModalNavigate(`/newsletters/${newsletterId}/preview?modal=1`, "Newsletter Vorschau");
                return;
            }

            const selectedEvent = eventSelect ? eventSelect.options[eventSelect.selectedIndex] : null;
            const previewTitle = document.getElementById("preview-modal-title");
            const previewProject = document.getElementById("preview-modal-project");
            const previewEvent = document.getElementById("preview-modal-event");
            const previewContent = document.getElementById("preview-modal-content");
            const editor = tinymce.get("content_html");

            if (previewTitle) {
                previewTitle.textContent = titleInput && titleInput.value ? titleInput.value : "Ohne Titel";
            }

            if (previewProject && projectSelect) {
                const selectedProject = projectSelect.options[projectSelect.selectedIndex];
                previewProject.textContent = selectedProject ? selectedProject.textContent.trim() : "";
            }

            if (previewEvent) {
                previewEvent.textContent = selectedEvent && selectedEvent.value
                    ? selectedEvent.textContent.trim()
                    : "Kein Event";
            }

            if (previewContent) {
                previewContent.innerHTML = editor ? editor.getContent() : "";
            }
        });
    }

    if (sendButton) {
        sendButton.addEventListener("click", function () {
            if (!confirm("Newsletter jetzt versenden?")) {
                return;
            }

            if (!isModalView) {
                sendForm.submit();
                return;
            }

            fetch(sendForm.getAttribute("action") || "", {
                method: "POST",
                headers: {
                    "X-Requested-With": "XMLHttpRequest",
                    ...(csrfToken ? { "X-CSRF-Token": csrfToken } : {}),
                },
            }).then(function (response) {
                if (!response.ok) {
                    alert("Fehler beim Versenden des Newsletters");
                    return;
                }

                if (typeof window.newsletterModalCloseAndRefresh === "function") {
                    window.newsletterModalCloseAndRefresh();
                    return;
                }

                window.location.reload();
            });
        });
    }

    if (saveTemplateButton) {
        saveTemplateButton.addEventListener("click", async function () {
            if (!newsletterId) {
                return;
            }

            const formData = new FormData();
            formData.set("template_name", templateNameInput ? templateNameInput.value : "");
            formData.set("template_description", templateDescriptionInput ? templateDescriptionInput.value : "");

            if (csrfToken) {
                formData.set("_csrf", csrfToken);
            }

            const response = await fetch(`/newsletters/${newsletterId}/save-as-template`, {
                method: "POST",
                body: formData,
                headers: csrfToken ? { "X-CSRF-Token": csrfToken } : {},
            });

            if (!response.ok) {
                alert("Fehler beim Speichern der Vorlage");
                return;
            }

            const modalElement = document.getElementById("saveTemplateModal");
            if (modalElement) {
                const modal = bootstrap.Modal.getInstance(modalElement);
                if (modal) {
                    modal.hide();
                }
            }
            alert("Vorlage gespeichert");
        });
    }

    if (newsletterId) {
        const lockIntervalId = setInterval(async function () {
            if (!document.body.contains(editForm)) {
                clearInterval(lockIntervalId);
                return;
            }

            const response = await fetch(`/newsletters/${newsletterId}/check-lock`);
            if (!response.ok) {
                return;
            }

            const data = await response.json();
            if (!data.locked || data.is_me) {
                return;
            }

            alert(`Newsletter wird jetzt von ${data.locked_by_user} bearbeitet`);
            if (isModalView && typeof window.newsletterModalCloseAndRefresh === "function") {
                window.newsletterModalCloseAndRefresh();
                return;
            }

            window.location.reload();
        }, 30000);
    }

    window.addEventListener("load", function () {
        filterEventsByProject();
        lastSavedSnapshot = createSnapshot();
    });

    const saveIntervalId = setInterval(function () {
        if (!document.body.contains(editForm)) {
            clearInterval(saveIntervalId);
            return;
        }

        saveNewsletter(false);
    }, 30000);
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initNewsletterEdit);
} else {
    initNewsletterEdit();
}
