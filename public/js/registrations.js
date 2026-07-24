(function () {
  "use strict";

  var STATUS_COLORS = { yes: "success", maybe: "warning", no: "danger" };

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
      button.classList.remove("btn-" + color, "btn-outline-" + color);
      button.classList.add(status === ownStatus ? "btn-" + color : "btn-outline-" + color);
    });
  }

  function applyCounts(form, counts) {
    if (!counts) {
      return;
    }
    var card = form.closest("[data-registration-card]");
    if (!card) {
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
    form.querySelectorAll("button[data-status]").forEach(function (button) {
      button.disabled = busy;
    });
  }

  async function submitStatus(form, status) {
    var body = new URLSearchParams();
    body.set("status", status);

    setBusy(form, true);
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
        window.alert(errorData.error || "Anmeldung konnte nicht gespeichert werden.");
        return;
      }

      var data = await response.json();
      applyOwnStatus(form, data.own_status);
      applyCounts(form, data.counts);
    } catch (_error) {
      window.alert("Anmeldung konnte nicht gespeichert werden.");
    } finally {
      setBusy(form, false);
    }
  }

  document.addEventListener("DOMContentLoaded", function () {
    document.querySelectorAll("form.registration-self-form").forEach(function (form) {
      form.addEventListener("submit", function (event) {
        event.preventDefault();
        var button = event.submitter;
        var status = button ? button.getAttribute("data-status") : null;
        if (!status) {
          return;
        }
        submitStatus(form, status);
      });
    });
  });
})();
