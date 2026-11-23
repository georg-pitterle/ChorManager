(function () {
  "use strict";

  function initTomSelects(root) {
    if (typeof TomSelect === "undefined") {
      return;
    }
    var scope = root && root.querySelectorAll ? root : document;
    scope.querySelectorAll("select[data-tom-select]").forEach(function (el) {
      if (el.tomselect) {
        return;
      }
      var allowCreate = el.hasAttribute("data-tom-select-create");
      new TomSelect(el, {
        plugins: ["remove_button"],
        maxOptions: null,
        hideSelected: true,
        placeholder: el.getAttribute("data-placeholder") || "",
        // Neue Einträge nur, wo das Feld es ausdrücklich erlaubt (Gruppen der Kasse).
        create: allowCreate,
        // Gruppen der Kasse werden in Journal und Export mit "; " getrennt.
        createFilter: allowCreate
          ? function (input) {
              return input.indexOf(";") === -1;
            }
          : null,
        createOnBlur: allowCreate,
        // "Neu: …" steht oben, damit Enter nach dem Tippen nicht still eine
        // ähnlich klingende vorhandene Gruppe übernimmt.
        addPrecedence: allowCreate,
        // Der Platzhalter ("keine") verschwindet, sobald eine Gruppe gewählt ist.
        hidePlaceholder: allowCreate,
        render: allowCreate
          ? {
              option_create: function (data, escape) {
                return '<div class="create">Neu: <strong>' + escape(data.input) + "</strong></div>";
              },
              // Neben "Neu: …" wäre "Keine Treffer" nur Rauschen.
              no_results: function () {
                return null;
              },
            }
          : {},
      });
    });
  }

  // Expose so dynamically injected content (e.g. the newsletter modal) can
  // trigger initialisation after it swaps in its markup.
  window.initTomSelects = initTomSelects;

  // Run immediately: when this script is (re-)loaded after markup already
  // exists in the DOM — such as the newsletter modal injecting content and
  // then loading its scripts — DOMContentLoaded has long since fired.
  initTomSelects(document);

  document.addEventListener("DOMContentLoaded", function () {
    initTomSelects(document);
  });

  // Bootstrap modals initialise their selects lazily; re-run when one opens.
  document.addEventListener("shown.bs.modal", function (event) {
    initTomSelects(event.target || document);
  });
})();
