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
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") || "";
    const isModal = form.getAttribute("data-is-modal") === "1";
    const audienceRows = form.querySelector("[data-audience-rows]");
    const eventSelect = form.querySelector("[data-newsletter-event-ids]");

    /**
     * Die Empfängerauswahl steht in echten Formularfeldern (Zielgruppen-Zeilen
     * audience[...] und event_ids[]); für die Vorschau werden genau diese Felder
     * aus dem Formular gelesen.
     */
    function audiencePayload() {
        const entries = [];
        new FormData(form).forEach((value, key) => {
            if (key.startsWith("audience[") || key === "event_ids[]") {
                entries.push([key, String(value)]);
            }
        });

        return entries;
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

        const payload = audiencePayload();
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
        payload.forEach(([key, value]) => requestData.append(key, value));
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

    // Jede Änderung an Zeilen oder Terminen zieht die Gesamtzahl nach.
    form.addEventListener("audience:change", refreshRecipientPreviewDebounced);
    if (eventSelect) {
        eventSelect.addEventListener("change", refreshRecipientPreviewDebounced);
    }

    // Eine Vorlage bringt die kompletten Newsletter-Einstellungen mit: Kontext,
    // Titelvorschlag und Empfänger ersetzen die bisherige Auswahl.
    //
    // Ersetzt wird nur, was die Vorlage auch festlegt: Eine globale Vorlage (ohne
    // Projekt) und eine Vorlage ohne Empfänger sagen nichts über den Kontext bzw.
    // den Verteiler aus - sie würden eine bewusste Auswahl sonst wegräumen.
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

        const sets = Array.isArray(data.audience) ? data.audience : [];
        const eventIds = Array.isArray(data.event_ids) ? data.event_ids.map(String) : [];
        if (sets.length === 0 && eventIds.length === 0) {
            refreshRecipientPreviewDebounced();
            return;
        }

        if (audienceRows && window.AudienceFilter) {
            window.AudienceFilter.setRows(audienceRows, sets);
        }
        if (eventSelect) {
            Array.from(eventSelect.options).forEach(option => {
                option.selected = eventIds.indexOf(option.value) !== -1;
            });
            // TomSelect rendert aus seinem eigenen Zustand; ohne setValue bliebe
            // die sichtbare Auswahl auf dem Stand vor dem Laden der Vorlage.
            if (eventSelect.tomselect) {
                eventSelect.tomselect.setValue(eventIds, true);
            }
        }

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

    refreshRecipientPreviewDebounced();
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initNewsletterCreate);
} else {
    initNewsletterCreate();
}
