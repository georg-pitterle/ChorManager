document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('mailboxForm');
    var feedback = document.getElementById('mailboxFormFeedback');

    if (!form || !feedback) {
        return;
    }

    function showFeedback(success, message) {
        feedback.classList.remove('d-none', 'alert-success', 'alert-danger');
        feedback.classList.add(success ? 'alert-success' : 'alert-danger');
        feedback.textContent = message;
    }

    function hideFeedback() {
        feedback.classList.add('d-none');
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        hideFeedback();

        var submitter = e.submitter;
        var url = (submitter && submitter.getAttribute('formaction')) || form.action;
        var isTest = url.indexOf('/profile/mailbox/test') !== -1;

        var originalHtml = submitter ? submitter.innerHTML : '';
        if (submitter) {
            submitter.disabled = true;
            submitter.textContent = 'Wird gesendet…';
        }

        fetch(url, {
            method: 'POST',
            headers: {
                'Accept': 'application/json'
            },
            body: new FormData(form)
        })
            .then(function (response) {
                return response.text().then(function (text) {
                    try {
                        return JSON.parse(text);
                    } catch (_) {
                        return {
                            success: false,
                            message: 'Verbindung zum Server fehlgeschlagen. Bitte versuche es erneut.'
                        };
                    }
                });
            })
            .then(function (data) {
                if (data.success && !isTest) {
                    window.location.reload();
                    return;
                }

                showFeedback(!!data.success, data.message || 'Unbekannter Fehler.');
                if (submitter) {
                    submitter.disabled = false;
                    submitter.innerHTML = originalHtml;
                }
            })
            .catch(function () {
                showFeedback(false, 'Verbindung zum Server fehlgeschlagen. Bitte versuche es erneut.');
                if (submitter) {
                    submitter.disabled = false;
                    submitter.innerHTML = originalHtml;
                }
            });
    });
});
