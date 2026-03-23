document.addEventListener('DOMContentLoaded', function () {
    const midiPlayers = document.querySelectorAll('midi-player');

    setTimeout(function () {
        if (!window.customElements || !window.customElements.get('midi-player')) {
            document.querySelectorAll('.midi-fallback').forEach(function (el) {
                el.classList.remove('d-none');
            });
            return;
        }

        midiPlayers.forEach(function (player) {
            player.addEventListener('error', function () {
                const fallback = player.parentElement.querySelector('.midi-fallback');
                if (fallback) {
                    fallback.classList.remove('d-none');
                }
            });
        });
    }, 1200);
});
