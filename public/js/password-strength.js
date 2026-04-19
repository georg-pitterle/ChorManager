document.addEventListener('DOMContentLoaded', function () {
    var input = document.getElementById('passwordInput');
    if (!input) {
        return;
    }

    var rules = {
        length: function (value) { return value.length >= 12; },
        upper: function (value) { return /[A-Z]/.test(value); },
        lower: function (value) { return /[a-z]/.test(value); },
        digit: function (value) { return /[0-9]/.test(value); },
        special: function (value) { return /[^A-Za-z0-9]/.test(value); }
    };
    var icons = {
        ok: 'bi-check-circle-fill',
        err: 'bi-x-circle-fill'
    };

    function update() {
        var value = input.value;

        Object.keys(rules).forEach(function (key) {
            var item = document.querySelector('#passwordStrengthList [data-rule="' + key + '"]');
            if (!item) {
                return;
            }

            var matches = rules[key](value);
            var icon = item.querySelector('i');

            if (icon) {
                icon.classList.toggle(icons.ok, matches);
                icon.classList.toggle(icons.err, !matches);
            }

            item.classList.toggle('text-success', matches);
            item.classList.toggle('text-danger', !matches);
        });
    }

    input.addEventListener('input', update);
    update();
});
