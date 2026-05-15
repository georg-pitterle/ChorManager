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
    const templateSelect = document.getElementById("template");
    const titleInput = document.getElementById("title");
    const recipientCountBadge = document.getElementById("recipient-count-badge");
    const recipientCountStatus = document.getElementById("recipient-count-status");
    const sourceProjectMembersCount = document.getElementById("source-project-members-count");
    const sourceEventAttendeesCount = document.getElementById("source-event-attendees-count");
    const sourceRolesCount = document.getElementById("source-roles-count");
    const sourceUsersCount = document.getElementById("source-users-count");
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") || "";
    const isModal = form.getAttribute("data-is-modal") === "1";
    const sourceBadgeMap = {
        project_members: sourceProjectMembersCount,
        event_attendees: sourceEventAttendeesCount,
        role: sourceRolesCount,
        user: sourceUsersCount,
    };
    const sourceTypes = Object.keys(sourceBadgeMap);

    function getSourceCheckboxes(type) {
        return Array.from(form.querySelectorAll(`input.newsletter-source-option[data-source-type="${type}"]`));
    }

    function buildRecipientSourcesPayload() {
        const payload = [];
        sourceTypes.forEach(type => {
            getSourceCheckboxes(type).forEach(checkbox => {
                if (!checkbox.checked) {
                    return;
                }

                const referenceId = Number(checkbox.value);
                if (!Number.isInteger(referenceId) || referenceId <= 0) {
                    return;
                }

                payload.push({ type, reference_id: referenceId });
            });
        });

        return payload;
    }

    function refreshSourceSelectionCounts() {
        sourceTypes.forEach(type => {
            const badge = sourceBadgeMap[type];
            if (!badge) {
                return;
            }

            const selectedCount = getSourceCheckboxes(type).filter(checkbox => checkbox.checked).length;
            badge.textContent = String(selectedCount);
        });
    }

    function syncSourcesHiddenInputs() {
        let hiddenContainer = document.getElementById("sources-hidden-inputs");
        if (!hiddenContainer) {
            hiddenContainer = document.createElement("div");
            hiddenContainer.id = "sources-hidden-inputs";
            hiddenContainer.className = "d-none";
            form.appendChild(hiddenContainer);
        }

        hiddenContainer.innerHTML = "";
        const payload = buildRecipientSourcesPayload();
        payload.forEach((source, index) => {
            const inputType = document.createElement("input");
            inputType.type = "hidden";
            inputType.name = `sources[${index}][type]`;
            inputType.value = source.type;

            const inputReference = document.createElement("input");
            inputReference.type = "hidden";
            inputReference.name = `sources[${index}][reference_id]`;
            inputReference.value = String(source.reference_id);

            hiddenContainer.appendChild(inputType);
            hiddenContainer.appendChild(inputReference);
        });

        return payload;
    }

    function debounce(fn, delayMs) {
        let timer = null;
        return function debounced(...args) {
            if (timer !== null) {
                window.clearTimeout(timer);
            }

            timer = window.setTimeout(() => {
                timer = null;
                fn.apply(this, args);
            }, delayMs);
        };
    }

    async function refreshRecipientPreview() {
        if (!recipientCountBadge) {
            return;
        }

        const payload = syncSourcesHiddenInputs();
        if (payload.length === 0) {
            recipientCountBadge.textContent = "0";
            if (recipientCountStatus) {
                recipientCountStatus.textContent = "";
            }
            return;
        }

        if (recipientCountStatus) {
            recipientCountStatus.textContent = "Aktualisiere...";
        }

        const requestData = new FormData();
        payload.forEach((source, index) => {
            requestData.append(`sources[${index}][type]`, source.type);
            requestData.append(`sources[${index}][reference_id]`, String(source.reference_id));
        });
        if (projectSelect && projectSelect.value) {
            requestData.append("project_id", projectSelect.value);
        }
        if (csrfToken) {
            requestData.append("_csrf", csrfToken);
        }

        try {
            const response = await fetch("/newsletters/resolve-recipients-preview", {
                method: "POST",
                body: requestData,
                headers: {
                    "X-Requested-With": "XMLHttpRequest",
                    ...(csrfToken ? { "X-CSRF-Token": csrfToken } : {}),
                },
            });

            if (!response.ok) {
                recipientCountBadge.textContent = "-";
                if (recipientCountStatus) {
                    recipientCountStatus.textContent = "Vorschau nicht verfügbar";
                }
                return;
            }

            const data = await response.json();
            recipientCountBadge.textContent = String(data.count ?? 0);
            if (recipientCountStatus) {
                recipientCountStatus.textContent = "";
            }
        } catch (_error) {
            recipientCountBadge.textContent = "-";
            if (recipientCountStatus) {
                recipientCountStatus.textContent = "Vorschau nicht verfügbar";
            }
        }
    }

    const refreshRecipientPreviewDebounced = debounce(refreshRecipientPreview, 300);

    function isSourceOptionTarget(target) {
        return !!(
            target
            && typeof target === "object"
            && target.classList
            && typeof target.classList.contains === "function"
            && target.classList.contains("newsletter-source-option")
        );
    }

    form.addEventListener("change", function (event) {
        if (!isSourceOptionTarget(event.target)) {
            return;
        }

        refreshSourceSelectionCounts();
        refreshRecipientPreviewDebounced();
    });

    form.addEventListener("input", function (event) {
        if (!isSourceOptionTarget(event.target)) {
            return;
        }

        refreshSourceSelectionCounts();
        refreshRecipientPreviewDebounced();
    });

    if (projectSelect) {
        projectSelect.addEventListener("change", function () {
            refreshSourceSelectionCounts();
            refreshRecipientPreviewDebounced();
        });
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
            } else {
                const textarea = document.getElementById("content_html");
                if (textarea) {
                    textarea.value = data.content_html || "";
                }
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

            syncSourcesHiddenInputs();
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

    syncSourcesHiddenInputs();
    refreshSourceSelectionCounts();
    refreshRecipientPreviewDebounced();
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initNewsletterCreate);
} else {
    initNewsletterCreate();
}
