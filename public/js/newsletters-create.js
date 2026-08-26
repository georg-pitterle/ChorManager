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

    function getSourceSelect(type) {
        return form.querySelector(`select.newsletter-source-select[data-source-type="${type}"]`);
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
            && target.classList.contains("newsletter-source-select")
        );
    }

    // Die versteckten Felder werden bei jeder Auswahl SOFORT nachgezogen. Im Modal baut
    // newsletters.js das FormData direkt aus dem Formular; liefe der Abgleich nur über die
    // entprellte Empfängervorschau, ginge eine Auswahl verloren, die kurz vor dem Absenden
    // getroffen wurde - der Newsletter käme dann ohne Empfängerquellen an.
    form.addEventListener("change", function (event) {
        if (!isSourceOptionTarget(event.target)) {
            return;
        }

        syncSourcesHiddenInputs();
        refreshSourceSelectionCounts();
        refreshRecipientPreviewDebounced();
    });

    form.addEventListener("input", function (event) {
        if (!isSourceOptionTarget(event.target)) {
            return;
        }

        syncSourcesHiddenInputs();
        refreshSourceSelectionCounts();
        refreshRecipientPreviewDebounced();
    });

    if (projectSelect) {
        projectSelect.addEventListener("change", function () {
            refreshSourceSelectionCounts();
            refreshRecipientPreviewDebounced();
        });
    }

    function setSourceSelection(type, referenceIds) {
        const select = getSourceSelect(type);
        if (!select) {
            return;
        }

        const wanted = referenceIds.map(referenceId => String(referenceId));
        Array.from(select.options).forEach(option => {
            option.selected = wanted.indexOf(option.value) !== -1;
        });

        // TomSelect rendert aus seinem eigenen Zustand; ohne sync bliebe die
        // sichtbare Auswahl auf dem Stand vor dem Laden der Vorlage.
        if (select.tomselect) {
            select.tomselect.setValue(wanted, true);
        }
    }

    // Eine Vorlage bringt die kompletten Newsletter-Einstellungen mit: Kontext,
    // Titelvorschlag und Empfängerquellen ersetzen die bisherige Auswahl.
    //
    // Ersetzt wird nur, was die Vorlage auch festlegt: Eine globale Vorlage (ohne
    // Projekt) und eine Vorlage ohne Empfängerquellen sagen nichts über den Kontext
    // bzw. den Verteiler aus - sie würden eine bewusste Auswahl sonst wegräumen.
    function applyTemplateSettings(data) {
        if (titleInput) {
            titleInput.value = data.default_title || data.name || "";
        }

        const templateProjectId = data.project_id === null || data.project_id === undefined
            ? null
            : String(data.project_id);
        if (projectSelect && templateProjectId !== null) {
            projectSelect.value = templateProjectId;
        }

        const sources = Array.isArray(data.recipient_sources) ? data.recipient_sources : [];
        if (sources.length === 0) {
            syncSourcesHiddenInputs();
            refreshSourceSelectionCounts();
            refreshRecipientPreviewDebounced();
            return;
        }

        sourceTypes.forEach(type => {
            const referenceIds = sources
                .filter(source => source && source.type === type)
                .map(source => Number(source.reference_id))
                .filter(referenceId => Number.isInteger(referenceId) && referenceId > 0);

            setSourceSelection(type, referenceIds);
        });

        syncSourcesHiddenInputs();
        refreshSourceSelectionCounts();
        refreshRecipientPreviewDebounced();
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

            applyTemplateSettings(data);
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
            const warnings = Array.isArray(data.warnings) ? data.warnings : [];
            if (warnings.length > 0) {
                alert(warnings.join(" "));
            }
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
