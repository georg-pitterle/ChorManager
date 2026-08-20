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

    // Ein Newsletter ohne aufgelöste Empfänger darf nicht versendet werden.
    // Der Server prüft es ebenfalls; hier geht es nur um die klare Anzeige.
    function applyRecipientCount(count) {
        if (recipientCountBadge) {
            recipientCountBadge.textContent = String(count);
        }

        if (!sendButton) {
            return;
        }

        const hasRecipients = Number(count) > 0;
        sendButton.disabled = !hasRecipients;
        sendButton.title = hasRecipients ? "" : "Kein Empfänger ausgewählt";
    }

    const sourceBadgeMap = {
        project_members: sourceProjectMembersCount,
        event_attendees: sourceEventAttendeesCount,
        role: sourceRolesCount,
        user: sourceUsersCount,
    };
    const sourceTypes = Object.keys(sourceBadgeMap);

    function getSourceSelect(type) {
        return editForm.querySelector(`select.newsletter-source-select[data-source-type="${type}"]`);
    }

    function getSelectedReferenceIds(type) {
        const select = getSourceSelect(type);
        if (!select) {
            return [];
        }

        return Array.from(select.selectedOptions)
            .map(option => Number(option.value))
            .filter(referenceId => Number.isInteger(referenceId) && referenceId > 0);
    }

    let lastSavedSnapshot = null;
    let saveInProgress = false;
    // Merkt sich die zuletzt angezeigte Warnung, damit ein automatischer
    // Hintergrund-Speicherlauf (alle 30 Sekunden) nicht bei jedem Durchlauf
    // erneut auf denselben unbekannten Platzhalter hinweist und die
    // Redaktion damit unterbricht. Ändert sich die Menge der unbekannten
    // Token, erscheint der Hinweis erneut.
    let lastWarningSignature = null;

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

    // Nach dem Anlegen lädt newsletters.js diesen Editor in den bestehenden Modal-Dialog nach,
    // statt eine echte Seite aufzurufen. Der Anlegen-Endpunkt liefert seine Platzhalter-Warnung
    // deshalb im JSON statt über die Sitzung (layout_modal.twig zeigt Sitzungsmeldungen ohnehin
    // nicht an). Damit newsletters.js die Warnung nach dem Nachladen mit demselben Meldungsweg
    // wie Speichern und Testmail anzeigen kann, wird die Funktion hier minimal nach außen sichtbar
    // gemacht.
    window.newsletterEditShowAlert = showEditAlert;

    function buildRecipientSourcesPayload() {
        const payload = [];
        sourceTypes.forEach(type => {
            getSelectedReferenceIds(type).forEach(referenceId => {
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

            badge.textContent = String(getSelectedReferenceIds(type).length);
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

    // Fortlaufende Anfragenummer: Läuft eine neuere Vorschau-Abfrage bereits,
    // darf die Antwort einer älteren, überholten Abfrage das Abzeichen und
    // die Versenden-Schaltfläche nicht mehr überschreiben.
    let recipientPreviewRequestId = 0;

    async function refreshRecipientPreview() {
        if (!recipientCountBadge) {
            return;
        }

        const payload = syncSourcesHiddenInputs();
        const requestId = ++recipientPreviewRequestId;

        if (payload.length === 0) {
            applyRecipientCount(0);
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

            if (requestId !== recipientPreviewRequestId) {
                return;
            }

            if (!response.ok) {
                recipientCountBadge.textContent = "-";
                if (sendButton) {
                    sendButton.disabled = true;
                    sendButton.title = "Empfängerzahl unbekannt";
                }
                if (recipientCountStatus) {
                    recipientCountStatus.textContent = "Vorschau nicht verfügbar";
                }
                return;
            }

            const data = await response.json();
            if (requestId !== recipientPreviewRequestId) {
                return;
            }

            applyRecipientCount(Number(data.count ?? 0));
            if (recipientCountStatus) {
                recipientCountStatus.textContent = "";
            }
        } catch (_error) {
            if (requestId !== recipientPreviewRequestId) {
                return;
            }

            recipientCountBadge.textContent = "-";
            if (sendButton) {
                sendButton.disabled = true;
                sendButton.title = "Empfängerzahl unbekannt";
            }
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

        // Der Modal-Weg schließt den Dialog nach dem Speichern und lädt die Seite neu, statt die
        // Warnung selbst anzuzeigen (siehe unten). Der Endpunkt braucht dieses Feld, um die
        // Platzhalter-Warnung dafür in die Sitzung zu legen, ohne sie auf dem klassischen
        // Seitenaufruf – der die Warnung bereits selbst über diese Antwort anzeigt – zusätzlich
        // über die Sitzung ein zweites Mal auftauchen zu lassen.
        formData.set("is_modal", isModalView ? "1" : "0");

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

            const data = await response.json();
            lastSavedSnapshot = currentSnapshot;

            const warnings = Array.isArray(data.warnings) ? data.warnings : [];
            const warningSignature = warnings.length > 0 ? warnings.join("|") : null;
            const isNewWarning = warningSignature !== null && warningSignature !== lastWarningSignature;
            lastWarningSignature = warningSignature;

            if (showSuccessMessage) {
                if (isModalView && typeof window.newsletterModalCloseAndRefresh === "function") {
                    window.newsletterModalCloseAndRefresh();
                    return;
                }

                if (warnings.length > 0) {
                    showEditAlert("warning", "Newsletter gespeichert. " + warnings.join(" "));
                } else {
                    showEditAlert("success", "Newsletter gespeichert");
                }
            } else if (isNewWarning) {
                showEditAlert("warning", warnings.join(" "));
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
            && target.classList.contains("newsletter-source-select")
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
            const recipientSelect = document.getElementById("preview-recipient");

            if (isModalView && newsletterId && typeof window.newsletterModalNavigate === "function") {
                // Die gespeicherte Vorschau akzeptiert und prüft recipient_id bereits serverseitig;
                // ohne diesen Parameter zeigt sie immer die eigenen Daten der Sitzung an, wodurch das
                // Auswahlfeld "Vorschau für" auf dem Modal-Weg wirkungslos bliebe.
                const selectedRecipientId = recipientSelect ? recipientSelect.value : "";
                const recipientQuery = selectedRecipientId
                    ? `&recipient_id=${encodeURIComponent(selectedRecipientId)}`
                    : "";
                window.newsletterModalNavigate(
                    `/newsletters/${newsletterId}/preview?modal=1${recipientQuery}`,
                    "Newsletter Vorschau"
                );
                return;
            }

            const previewTitle = document.getElementById("preview-modal-title");
            const previewProject = document.getElementById("preview-modal-project");
            const previewContent = document.getElementById("preview-modal-content");
            const editor = typeof tinymce !== "undefined" ? tinymce.get("content_html") : null;

            if (previewProject && projectSelect) {
                const selectedProject = projectSelect.options[projectSelect.selectedIndex];
                previewProject.textContent = selectedProject ? selectedProject.textContent.trim() : "";
            }

            const body = new FormData();
            body.set("title", titleInput && titleInput.value ? titleInput.value : "Ohne Titel");
            body.set("content_html", editor ? editor.getContent() : "");
            body.set("recipient_id", recipientSelect ? recipientSelect.value : "");
            if (csrfToken) {
                body.set("_csrf", csrfToken);
            }

            fetch(`/newsletters/${newsletterId}/preview-render`, {
                method: "POST",
                headers: {
                    "X-Requested-With": "XMLHttpRequest",
                    ...(csrfToken ? { "X-CSRF-Token": csrfToken } : {}),
                },
                body: body
            })
                .then(function (response) {
                    return response.json().then(function (data) {
                        return { ok: response.ok, data: data };
                    });
                })
                .then(function (result) {
                    if (!result.ok) {
                        if (previewContent) {
                            previewContent.textContent = (result.data && result.data.error)
                                || "Vorschau konnte nicht geladen werden.";
                        }
                        return;
                    }

                    const data = result.data;
                    if (previewTitle) {
                        previewTitle.textContent = data.title || "Ohne Titel";
                    }

                    if (previewContent) {
                        previewContent.innerHTML = data.content_html || "";
                    }
                })
                .catch(function () {
                    if (previewContent) {
                        previewContent.textContent = "Vorschau konnte nicht geladen werden.";
                    }
                });
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

    const testMailButton = document.getElementById("test-mail-btn");
    if (testMailButton) {
        testMailButton.addEventListener("click", function () {
            const editor = typeof tinymce !== "undefined" ? tinymce.get("content_html") : null;
            const body = new FormData();
            body.set("title", titleInput && titleInput.value ? titleInput.value : "Ohne Titel");
            body.set("content_html", editor ? editor.getContent() : "");

            testMailButton.disabled = true;

            fetch(`/newsletters/${newsletterId}/test-mail`, {
                method: "POST",
                headers: {
                    "X-Requested-With": "XMLHttpRequest",
                    ...(csrfToken ? { "X-CSRF-Token": csrfToken } : {}),
                },
                body: body
            })
                .then(function (response) {
                    return response.json().then(function (data) {
                        return { ok: response.ok, data: data };
                    });
                })
                .then(function (result) {
                    const message = (result.data && (result.data.message || result.data.error))
                        || "Testmail konnte nicht gesendet werden.";
                    showEditAlert(result.ok ? "success" : "danger", message);
                })
                .catch(function () {
                    showEditAlert("danger", "Testmail konnte nicht gesendet werden.");
                })
                .finally(function () {
                    testMailButton.disabled = false;
                });
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

    // Setzt den Anfangszustand (u. a. die Versenden-Sperre ohne Empfänger).
    // Wird unten direkt aufgerufen, damit es auch im Modal-Workflow greift –
    // dort injiziert newsletters.js dieses Skript dynamisch NACH dem
    // load-Ereignis, weshalb ein reiner load-Listener dort nie ausgelöst
    // würde. Im klassischen Seitenaufruf, wo das Dokument beim Ausführen
    // dieses Scripts noch lädt, hängt sich runInitialSync() zusätzlich an
    // load, damit sie auch dort sicher zum Zug kommt. Der Ausführungs-Schutz
    // verhindert, dass beide Wege doppelt initialisieren.
    let initialSyncDone = false;

    function runInitialSync() {
        if (initialSyncDone) {
            return;
        }
        initialSyncDone = true;

        syncSourcesHiddenInputs();
        refreshSourceSelectionCounts();
        applyRecipientCount(Number(recipientCountBadge ? recipientCountBadge.textContent : 0));
        refreshRecipientPreviewDebounced();
        lastSavedSnapshot = createSnapshot();
    }

    if (document.readyState !== "complete") {
        window.addEventListener("load", runInitialSync);
    }

    const saveIntervalId = setInterval(function () {
        if (!document.body.contains(editForm)) {
            clearInterval(saveIntervalId);
            return;
        }

        saveNewsletter(false);
    }, 30000);

    runInitialSync();
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initNewsletterEdit);
} else {
    initNewsletterEdit();
}
