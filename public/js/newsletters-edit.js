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
    const titleInput = document.getElementById("title");
    const recipientCountBadge = document.getElementById("recipient-count-badge");
    const recipientCountStatus = document.getElementById("recipient-count-status");
    const sourceProjectMembersCount = document.getElementById("source-project-members-count");
    const sourceEventAttendeesCount = document.getElementById("source-event-attendees-count");
    const sourceRolesCount = document.getElementById("source-roles-count");
    const sourceUsersCount = document.getElementById("source-users-count");
    const saveDraftButton = document.getElementById("save-draft-btn");
    const previewButton = document.getElementById("preview-btn");
    const sendButton = document.getElementById("send-newsletter-btn");
    const saveTemplateButton = document.getElementById("save-template-btn");
    const templateNameInput = document.getElementById("template_name");
    const templateDescriptionInput = document.getElementById("template_description");
    const sourceBadgeMap = {
        project_members: sourceProjectMembersCount,
        event_attendees: sourceEventAttendeesCount,
        role: sourceRolesCount,
        user: sourceUsersCount,
    };
    const sourceTypes = Object.keys(sourceBadgeMap);

    function getSourceCheckboxes(type) {
        return Array.from(editForm.querySelectorAll(`input.newsletter-source-option[data-source-type="${type}"]`));
    }

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
            editForm.appendChild(hiddenContainer);
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

    function createSnapshot() {
        const projectId = projectSelect ? projectSelect.value : "";
        const title = titleInput ? titleInput.value : "";
        const editor = tinymce.get("content_html");
        const contentHtml = editor ? editor.getContent() : "";
        const sources = buildRecipientSourcesPayload();

        return JSON.stringify({
            project_id: projectId,
            title: title,
            sources: sources,
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
        syncSourcesHiddenInputs();
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

    function isSourceOptionTarget(target) {
        return !!(
            target
            && typeof target === "object"
            && target.classList
            && typeof target.classList.contains === "function"
            && target.classList.contains("newsletter-source-option")
        );
    }

    editForm.addEventListener("change", function (event) {
        if (!isSourceOptionTarget(event.target)) {
            return;
        }

        refreshSourceSelectionCounts();
        refreshRecipientPreviewDebounced();
    });

    editForm.addEventListener("input", function (event) {
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

            const previewTitle = document.getElementById("preview-modal-title");
            const previewProject = document.getElementById("preview-modal-project");
            const previewContent = document.getElementById("preview-modal-content");
            const editor = tinymce.get("content_html");

            if (previewTitle) {
                previewTitle.textContent = titleInput && titleInput.value ? titleInput.value : "Ohne Titel";
            }

            if (previewProject && projectSelect) {
                const selectedProject = projectSelect.options[projectSelect.selectedIndex];
                previewProject.textContent = selectedProject ? selectedProject.textContent.trim() : "";
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
            }).then(async function (response) {
                if (!response.ok) {
                    alert("Fehler beim Versenden des Newsletters");
                    return;
                }

                const responseType = response.headers.get("Content-Type") || "";
                if (responseType.includes("application/json")) {
                    const payload = await response.json();
                    if (payload && payload.redirect) {
                        window.location.href = payload.redirect;
                        return;
                    }
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
        const releaseLockOnLeave = function () {
            const beaconData = new FormData();
            if (csrfToken) {
                beaconData.append("_csrf", csrfToken);
            }
            navigator.sendBeacon(`/newsletters/${newsletterId}/release-lock`, beaconData);
        };
        window.addEventListener("pagehide", releaseLockOnLeave);

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
        syncSourcesHiddenInputs();
        refreshSourceSelectionCounts();
        refreshRecipientPreviewDebounced();
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
