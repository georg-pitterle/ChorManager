document.addEventListener("DOMContentLoaded", function () {
    const archiveSection = document.getElementById("sheetArchiveSection");
    const container = document.getElementById("voiceItemsContainer");
    const addButton = document.getElementById("addVoiceItemButton");
    const totalCountDisplay = document.getElementById("totalCount");
    const locationInput = document.getElementById("location");
    const headerLocation = document.getElementById("sheetArchiveHeaderLocation");
    const headerSummary = document.getElementById("sheetArchiveHeaderSummary");
    const saveStatus = document.getElementById("sheetArchiveSaveStatus");
    const suggestionsList = document.getElementById("sheetArchiveVoiceSuggestions");
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") || "";
    const archiveForm = document.querySelector('form[data-archive-form="1"]');
    let isSubmitting = false;

    if (!archiveSection || !container || !addButton || !totalCountDisplay || !locationInput || !headerLocation || !headerSummary || !archiveForm) {
        return;
    }

    // Load known categories for datalist suggestions.
    loadVoiceCategorySuggestions();

    // Update total count.
    updateTotalCount();
    updateHeaderSummary();

    // Add new voice item.
    addButton.addEventListener("click", function () {
        addVoiceItem();
    });

    locationInput.addEventListener("input", function () {
        updateHeaderSummary();
    });

    archiveForm.addEventListener("submit", async function (event) {
        if (isSubmitting) {
            return;
        }

        event.preventDefault();

        const archiveAction = archiveForm.getAttribute("action") || "";
        const songId = extractSongIdFromAction(archiveAction);
        if (!songId) {
            setSaveStatus("Archivdaten konnten nicht gespeichert werden.", true);
            return;
        }

        isSubmitting = true;
        setSaveStatus("Notenarchiv wird gespeichert …", false);

        const payload = collectArchivePayload();
        const requestBody = toUrlEncodedPayload(payload, csrfToken);

        try {
            const response = await fetch(archiveAction, {
                method: "POST",
                headers: {
                    Accept: "application/json",
                },
                body: requestBody,
            });

            const responseData = await parseJsonResponse(response);
            if (!response.ok) {
                setSaveStatus(responseData.error || "Archivdaten konnten nicht gespeichert werden.", true);
                isSubmitting = false;
                return;
            }

            syncVoiceIdsFromResponse(responseData);
            updateHeaderSummary();
            setSaveStatus("Notenarchiv erfolgreich gespeichert.", false);
        } catch (error) {
            console.error("Failed to save sheet archive data.", error);
            setSaveStatus("Archivdaten konnten nicht gespeichert werden.", true);
        } finally {
            isSubmitting = false;
        }
    });

    // Delegate event listeners.
    container.addEventListener("change", function (event) {
        if (event.target.classList.contains("voice-count")) {
            updateTotalCount();
            updateHeaderSummary();
        }

        if (event.target.classList.contains("voice-category")) {
            updateHeaderSummary();
        }
    });

    container.addEventListener("input", function (event) {
        if (event.target.classList.contains("voice-count")) {
            updateTotalCount();
            updateHeaderSummary();
        }

        if (event.target.classList.contains("voice-category")) {
            updateHeaderSummary();
        }
    });

    container.addEventListener("click", function (event) {
        if (event.target.closest(".delete-voice-item")) {
            event.target.closest(".voice-item-row").remove();
            updateTotalCount();
            updateHeaderSummary();
        }
    });

    function addVoiceItem() {
        const row = document.createElement("div");
        row.className = "voice-item-row mb-2";
        row.innerHTML = `
            <div class="input-group">
                <input type="hidden" class="voice-id" value="">
                <input type="text" class="form-control voice-category creatable-input" list="sheetArchiveVoiceSuggestions" placeholder="Stimme eingeben" value="">
                <input type="number" class="form-control voice-count" min="0" value="0" style="max-width: 100px">
                <button type="button" class="btn btn-danger btn-sm delete-voice-item">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        `;
        container.appendChild(row);
        updateTotalCount();
        updateHeaderSummary();
    }

    function extractSongIdFromAction(actionUrl) {
        const match = actionUrl.match(/\/song-library\/songs\/(\d+)\/archive\/save$/);
        return match ? parseInt(match[1], 10) : null;
    }

    function collectArchivePayload() {
        const archiveNumber = document.getElementById("archive_number")?.value?.trim() || null;
        const location = document.getElementById("location")?.value?.trim() || null;
        const lineItems = [];

        container.querySelectorAll(".voice-item-row").forEach(function (row) {
            const categoryInput = row.querySelector(".voice-category");
            const countInput = row.querySelector(".voice-count");

            const voiceCategory = (categoryInput?.value || "").trim();
            const count = parseInt(countInput?.value || "0", 10) || 0;

            // Ignore untouched empty row from initial rendering.
            if (voiceCategory === "" && count === 0) {
                return;
            }

            lineItems.push({
                voice_category: voiceCategory,
                count,
            });
        });

        return {
            archive_number: archiveNumber,
            location,
            line_items: lineItems,
        };
    }

    function toUrlEncodedPayload(payload, csrf) {
        const params = new URLSearchParams();
        if (csrf) {
            params.append("_csrf", csrf);
        }

        params.append("archive_number", payload.archive_number || "");
        params.append("location", payload.location || "");

        payload.line_items.forEach(function (item, index) {
            params.append(`line_items[${index}][voice_category]`, item.voice_category);
            params.append(`line_items[${index}][count]`, String(item.count));
        });

        return params;
    }

    async function parseJsonResponse(response) {
        const contentType = (response.headers.get("content-type") || "").toLowerCase();
        if (!contentType.includes("application/json")) {
            return {};
        }

        try {
            return await response.json();
        } catch (_error) {
            return {};
        }
    }

    function syncVoiceIdsFromResponse(responseData) {
        const archive = responseData?.archive;
        const lineItems = Array.isArray(archive?.line_items) ? archive.line_items : [];
        if (lineItems.length === 0) {
            return;
        }

        const idsByCategory = new Map();
        lineItems.forEach(function (item) {
            if (typeof item.voice_category === "string" && item.voice_category.trim() !== "") {
                idsByCategory.set(item.voice_category.trim(), item.id || "");
            }
        });

        container.querySelectorAll(".voice-item-row").forEach(function (row) {
            const categoryInput = row.querySelector(".voice-category");
            const idInput = row.querySelector(".voice-id");
            const category = (categoryInput?.value || "").trim();

            if (!idInput || category === "") {
                return;
            }

            if (idsByCategory.has(category)) {
                idInput.value = String(idsByCategory.get(category));
            }
        });
    }

    async function loadVoiceCategorySuggestions() {
        if (!suggestionsList) {
            return;
        }

        try {
            const response = await fetch("/song-library/archive/voice-categories", {
                headers: {
                    Accept: "application/json",
                },
            });

            if (!response.ok) {
                return;
            }

            const payload = await response.json();
            const categories = Array.isArray(payload.categories) ? payload.categories : [];

            suggestionsList.innerHTML = "";
            categories.forEach(function (category) {
                if (typeof category !== "string" || category.trim() === "") {
                    return;
                }

                const option = document.createElement("option");
                option.value = category;
                suggestionsList.appendChild(option);
            });
        } catch (error) {
            // Keep input usable even if suggestions cannot be loaded.
            console.warn("Voice category suggestions could not be loaded.", error);
        }
    }

    function updateTotalCount() {
        let total = 0;
        container.querySelectorAll(".voice-count").forEach(function (input) {
            const value = parseInt(input.value, 10) || 0;
            total += value;
        });
        totalCountDisplay.textContent = total;
    }

    function updateHeaderSummary() {
        const locationValue = (locationInput.value || "").trim();
        headerLocation.textContent = locationValue !== "" ? locationValue : "kein Standort";

        const parts = [];
        container.querySelectorAll(".voice-item-row").forEach(function (row) {
            const category = (row.querySelector(".voice-category")?.value || "").trim();
            const count = parseInt(row.querySelector(".voice-count")?.value || "0", 10) || 0;

            if (category === "") {
                return;
            }

            parts.push(`${category}: ${count}`);
        });

        headerSummary.textContent = parts.length > 0 ? parts.join(", ") : "keine Einzelstimmen";
    }

    function setSaveStatus(message, isError) {
        if (!saveStatus) {
            return;
        }

        saveStatus.textContent = message;
        saveStatus.classList.toggle("text-danger", !!isError);
        saveStatus.classList.toggle("text-success", !isError && message !== "");
        if (!isError) {
            saveStatus.classList.remove("text-muted");
        }
    }
});
