(function () {
  "use strict";

  var STATUS_COLORS = { yes: "success", maybe: "warning", no: "danger" };
  var STATUS_LABELS = { yes: "Zusage", maybe: "Vielleicht", no: "Absage" };
  var FALLBACK_ERROR = "Nicht gespeichert – bitte Verbindung prüfen und erneut tippen.";

  function csrfToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute("content") || "" : "";
  }

  function applyOwnStatus(form, ownStatus) {
    form.querySelectorAll("button[data-status]").forEach(function (button) {
      var status = button.getAttribute("data-status");
      var color = STATUS_COLORS[status];
      if (!color) {
        return;
      }
      var active = status === ownStatus;
      button.classList.remove("btn-" + color, "btn-outline-" + color);
      button.classList.add(active ? "btn-" + color : "btn-outline-" + color);
      button.setAttribute("aria-pressed", active ? "true" : "false");
    });

    var reset = form.querySelector('button[data-status="open"]');
    if (reset) {
      // Ein verschwindender Knopf mit Fokus ließe die Tastatur am Seitenanfang landen.
      if (!ownStatus && document.activeElement === reset) {
        var first = form.querySelector("button[data-status]");
        if (first) {
          first.disabled = false;
          first.focus();
        }
      }
      reset.hidden = !ownStatus;
    }
  }

  function setStatusText(form, text, isError) {
    var target = form.querySelector("[data-answer-status]");
    if (!target) {
      return;
    }
    target.textContent = text;
    target.classList.toggle("is-error", Boolean(isError));
    target.classList.toggle("is-saved", !isError);
  }

  function applyUnanswered(card, ownStatus) {
    var unanswered = !ownStatus;
    card.setAttribute("data-unanswered", unanswered ? "1" : "0");
    card.classList.toggle("is-unanswered", unanswered);

    var badge = card.querySelector("[data-missing-badge]");
    if (!unanswered && badge) {
      badge.remove();
    } else if (unanswered && !badge) {
      var aside = card.querySelector(".event-plan__aside");
      if (aside) {
        badge = document.createElement("span");
        badge.className = "registration-missing";
        badge.setAttribute("data-missing-badge", "");
        badge.textContent = "Antwort fehlt";
        aside.insertBefore(badge, aside.querySelector(".badge"));
      }
    }
  }

  // Die Zeile oben folgt den Karten, damit "3 Termine warten" nach jeder Antwort stimmt.
  function refreshSummary() {
    var summary = document.querySelector("[data-registration-summary]");
    if (!summary) {
      return;
    }
    var pending = document.querySelectorAll('[data-registration-card][data-unanswered="1"]').length;
    // Unveränderter Text bleibt stehen: sonst sagt der Screenreader die Zeile bei jedem Wechsel neu an.
    if (summary.getAttribute("data-pending") === String(pending)) {
      return;
    }
    summary.setAttribute("data-pending", String(pending));
    var icon = document.createElement("i");
    icon.setAttribute("aria-hidden", "true");
    var text = document.createElement("span");

    if (pending > 0) {
      icon.className = "bi bi-hourglass-split";
      var strong = document.createElement("strong");
      strong.textContent = pending + (pending === 1 ? " Termin wartet" : " Termine warten");
      text.appendChild(strong);
      text.appendChild(document.createTextNode(" auf deine Antwort."));
    } else {
      icon.className = "bi bi-check2-all";
      text.textContent = "Du hast auf alle Termine geantwortet – danke!";
    }

    summary.classList.toggle("is-pending", pending > 0);
    summary.replaceChildren(icon, text);
  }

  function applyCounts(card, counts) {
    if (!counts) {
      return;
    }
    ["yes", "no", "maybe", "open"].forEach(function (key) {
      if (typeof counts[key] === "undefined") {
        return;
      }
      var target = card.querySelector('[data-count="' + key + '"]');
      if (target) {
        target.textContent = String(counts[key]);
      }
    });
  }

  function setBusy(form, busy) {
    form.setAttribute("aria-busy", busy ? "true" : "false");
    form.querySelectorAll("button[data-status]").forEach(function (button) {
      button.disabled = busy;
    });
  }

  async function submitStatus(form, status) {
    var card = form.closest("[data-registration-card]");
    var body = new URLSearchParams();
    body.set("status", status);

    setBusy(form, true);
    setStatusText(form, "Wird gespeichert …", false);
    try {
      var response = await fetch(form.getAttribute("action") || "", {
        method: "POST",
        headers: {
          "X-Requested-With": "XMLHttpRequest",
          "X-CSRF-Token": csrfToken(),
          "Content-Type": "application/x-www-form-urlencoded",
          Accept: "application/json",
        },
        body: body.toString(),
      });

      if (!response.ok) {
        var errorData = await response.json().catch(function () {
          return {};
        });
        setStatusText(form, errorData.error || FALLBACK_ERROR, true);
        return;
      }

      var data = await response.json();
      var ownStatus = data.own_status || null;
      applyOwnStatus(form, ownStatus);
      setStatusText(
        form,
        ownStatus ? "Gespeichert – deine Antwort: " + STATUS_LABELS[ownStatus] : "Antwort zurückgenommen",
        false
      );
      if (card) {
        applyCounts(card, data.counts);
        applyUnanswered(card, ownStatus);
      }
      refreshSummary();
    } catch (_error) {
      setStatusText(form, FALLBACK_ERROR, true);
    } finally {
      setBusy(form, false);
    }
  }

  document.addEventListener("DOMContentLoaded", function () {
    document.querySelectorAll("form.registration-self-form").forEach(function (form) {
      var status = form.querySelector("[data-answer-status]");
      if (status) {
        // Erst nach dem Laden ansagen: sonst liest der Screenreader jede Karte beim Seitenaufbau vor.
        status.setAttribute("role", "status");
      }

      form.addEventListener("submit", function (event) {
        event.preventDefault();
        var button = event.submitter;
        var value = button ? button.getAttribute("data-status") : null;
        if (!value) {
          return;
        }
        submitStatus(form, value);
      });
    });
  });
})();
