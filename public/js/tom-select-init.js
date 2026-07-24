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
      new TomSelect(el, {
        plugins: ["remove_button"],
        maxOptions: null,
        hideSelected: true,
        placeholder: el.getAttribute("data-placeholder") || "",
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
