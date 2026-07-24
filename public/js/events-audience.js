(function () {
  "use strict";

  function collectSources(root) {
    var selects = root.querySelectorAll("select.event-audience-source");
    var sources = [];
    selects.forEach(function (select) {
      var type = select.getAttribute("data-source-type");
      Array.prototype.forEach.call(select.selectedOptions, function (option) {
        var referenceId = parseInt(option.value, 10);
        if (!isNaN(referenceId)) {
          sources.push({ type: type, reference_id: referenceId });
        }
      });
    });
    return sources;
  }

  function wireForm(form) {
    var hidden = form.querySelector(".event-audience-json");
    if (!hidden) {
      return;
    }
    form.addEventListener("submit", function () {
      hidden.value = JSON.stringify(collectSources(form));
    });
  }

  document.addEventListener("DOMContentLoaded", function () {
    document.querySelectorAll("form.event-audience-form").forEach(wireForm);
  });
})();
